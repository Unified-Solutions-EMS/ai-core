<?php

declare(strict_types=1);

namespace Unified\AiCore\Proposal;

use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;
use Unified\AiCore\Ledger\Run;
use Unified\AiCore\Ledger\RunRecorder;
use Unified\AiCore\Phi\PurgeHooks;
use Unified\AiCore\Phi\PurgeReason;
use Unified\AiCore\Support\SsoEndpoint;

/**
 * The approval checkpoint every AI item shares (plan §2.4):
 *
 *   draft → refine* → approve(user, instruction, plan hash) → execute →
 *   verify, or reject at any point before execution.
 *
 * Every transition is a compare-and-set against the status (and, where it
 * matters, the plan hash) the caller loaded. Two requests acting on the
 * same proposal cannot both win: the loser gets ProposalStateChanged and
 * nothing it would have written is written. Approval binds to the plan
 * hash, and the execution claim requires plan_hash = approved_plan_hash =
 * the hash of the plan being run, in the same conditional update.
 *
 * The executor is app-supplied: nothing here writes app data. It must be
 * idempotent per change. A worker that dies mid-execution leaves the row
 * 'executing' until ai:recover-stuck-proposals marks it failed, and a
 * person may then retry changes that had already been applied.
 */
class ProposalService
{
    public function __construct(
        private readonly RunRecorder $recorder,
        private readonly PurgeHooks $purgeHooks,
    ) {}

    public function draft(string $domain, Plan $plan, ?int $companyId, ?int $createdBy, ?Run $run = null): Proposal
    {
        if ($run !== null && ($run->input_snapshot['writes'] ?? null) === 'direct') {
            throw new LogicException('A run that wrote directly cannot become a proposal: its changes were never held for approval.');
        }

        /** @var Proposal $proposal */
        $proposal = $this->model()::query()->create([
            'app_slug' => SsoEndpoint::appSlug() ?: null,
            'domain' => $domain,
            'company_id' => $companyId,
            'created_by' => $createdBy,
            'status' => ProposalStatus::Drafted,
            'plan' => $plan->toArray(),
            'plan_hash' => $plan->hash(),
            'run_id' => $run?->id,
        ]);

        if ($run !== null) {
            $this->recorder->attachProposal($run, $proposal->id);
        }

        return $proposal;
    }

    /**
     * Replace the plan. Any earlier approval no longer applies.
     */
    public function refine(Proposal $proposal, Plan $plan): Proposal
    {
        $this->transition($proposal, ProposalStatus::Refined, 'refined', [
            'plan' => $plan->toArray(),
            'plan_hash' => $plan->hash(),
            'approved_plan_hash' => null,
            'approved_by' => null,
            'approved_at' => null,
            'approval_instruction' => null,
        ], matchHash: true);

        return $proposal;
    }

    /**
     * Record the explicit "Approve and apply" click. Pass the hash the
     * person was shown ($expectedHash) so an approval of a stale plan is
     * refused rather than applied to a newer one.
     */
    public function approve(Proposal $proposal, int $userId, string $instruction, ?string $expectedHash = null): Proposal
    {
        if ($expectedHash !== null && ! hash_equals($proposal->plan_hash, $expectedHash)) {
            throw ProposalStateException::planChanged();
        }

        $this->transition($proposal, ProposalStatus::Approved, 'approved', [
            'approved_plan_hash' => $proposal->plan_hash,
            'approved_by' => $userId,
            'approved_at' => now(),
            'approval_instruction' => $instruction,
        ], matchHash: true);

        if ($run = $this->run($proposal)) {
            $this->recorder->markApproved($run, $userId);
        }

        return $proposal;
    }

    /**
     * Run the approved plan through the app's executor, one change at a
     * time. A change that throws is recorded as failed and the rest still
     * run (unless $stopOnFailure); the proposal ends executed when every
     * change succeeded, failed otherwise.
     *
     * @param  callable(PlannedChange, int, Proposal): mixed  $executor  idempotent per change; returns a JSON-serialisable result
     */
    public function execute(Proposal $proposal, callable $executor, bool $stopOnFailure = false, ?int $executedBy = null): Proposal
    {
        if ($proposal->status !== ProposalStatus::Approved) {
            throw ProposalStateException::cannot('executed', $proposal->status);
        }

        $plan = $proposal->planObject();
        $hash = $plan->hash();
        $claimedAt = now();

        $claimed = $this->model()::query()
            ->whereKey($proposal->id)
            ->where('status', ProposalStatus::Approved->value)
            ->where('plan_hash', $hash)
            ->where('approved_plan_hash', $hash)
            ->update([
                'status' => ProposalStatus::Executing->value,
                'executing_at' => $claimedAt,
                'executing_by' => $executedBy,
                'updated_at' => $claimedAt,
            ]);

        if ($claimed !== 1) {
            $this->explainFailedClaim($proposal, $hash);
        }

        $proposal->forceFill(['status' => ProposalStatus::Executing, 'executing_at' => $claimedAt, 'executing_by' => $executedBy])->syncOriginal();

        $results = [];
        $failed = false;

        foreach ($plan->changes as $index => $change) {
            if ($failed && $stopOnFailure) {
                $results[] = ['index' => $index, 'ok' => false, 'skipped' => true];

                continue;
            }

            try {
                $results[] = ['index' => $index, 'ok' => true, 'result' => $executor($change, $index, $proposal)];
            } catch (Throwable $e) {
                $failed = true;
                $results[] = ['index' => $index, 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        $finished = $this->model()::query()
            ->whereKey($proposal->id)
            ->where('status', ProposalStatus::Executing->value)
            ->where('executing_at', $claimedAt)
            ->update([
                'status' => ($failed ? ProposalStatus::Failed : ProposalStatus::Executed)->value,
                'executed_at' => now(),
                'results' => json_encode($results),
                'updated_at' => now(),
            ]);

        if ($finished !== 1) {
            // Marked stuck (and failed) while this worker was still running.
            // Keep the results for the audit trail; leave the status alone.
            $this->model()::query()->whereKey($proposal->id)->update(['results' => json_encode($results)]);
            $proposal->refresh();

            Log::warning('AI proposal finished after it was marked stuck', ['proposal' => $proposal->uuid, 'status' => $proposal->status->value]);

            throw ProposalStateChanged::lostRace('executed', ProposalStatus::Executing, $proposal->status);
        }

        $proposal->refresh();

        if ($run = $this->run($proposal)) {
            $this->recorder->markExecuted($run, [
                'status' => $proposal->status->value,
                'succeeded' => count(array_filter($results, fn (array $r): bool => $r['ok'])),
                'failed' => count(array_filter($results, fn (array $r): bool => ! $r['ok'])),
            ]);
        }

        if (! $failed) {
            $this->purge($proposal, PurgeReason::Executed);
        }

        return $proposal;
    }

    /**
     * The second, "looks good" confirmation after execution.
     */
    public function verify(Proposal $proposal, int $userId): Proposal
    {
        $this->transition($proposal, ProposalStatus::Verified, 'verified', [
            'verified_by' => $userId,
            'verified_at' => now(),
        ]);

        return $proposal;
    }

    public function reject(Proposal $proposal, ?int $userId, string $reason): Proposal
    {
        $this->transition($proposal, ProposalStatus::Rejected, 'rejected', [
            'rejected_by' => $userId,
            'rejected_reason' => $reason,
        ]);

        $this->purge($proposal, PurgeReason::Abandoned);

        return $proposal;
    }

    /**
     * Move an execution that never finished to failed. Conditional on the
     * claim the caller saw, so a worker that finishes first wins.
     */
    public function markStuck(Proposal $proposal, string $reason): bool
    {
        if ($proposal->status !== ProposalStatus::Executing || $proposal->executing_at === null) {
            return false;
        }

        $moved = $this->model()::query()
            ->whereKey($proposal->id)
            ->where('status', ProposalStatus::Executing->value)
            ->where('executing_at', $proposal->getRawOriginal('executing_at'))
            ->update([
                'status' => ProposalStatus::Failed->value,
                'failed_reason' => $reason,
                'updated_at' => now(),
            ]);

        $proposal->refresh();

        return $moved === 1;
    }

    /**
     * Compare-and-set from the status (and plan hash) this instance was
     * loaded with. Throws, writing nothing, when the transition is not
     * allowed or another request got there first.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function transition(Proposal $proposal, ProposalStatus $next, string $action, array $attributes, bool $matchHash = false): void
    {
        $loadedStatus = ProposalStatus::from((string) $proposal->getRawOriginal('status'));
        $loadedHash = (string) $proposal->getRawOriginal('plan_hash');

        if ($loadedStatus === ProposalStatus::Executing) {
            throw ProposalStateException::executing($action);
        }

        if (! $loadedStatus->canTransitionTo($next)) {
            throw ProposalStateException::cannot($action, $loadedStatus);
        }

        $proposal->forceFill(['status' => $next, ...$attributes]);
        $proposal->updated_at = now();
        $changes = $proposal->getDirty();

        $query = $this->model()::query()
            ->whereKey($proposal->id)
            ->where('status', $loadedStatus->value);

        if ($matchHash) {
            $query->where('plan_hash', $loadedHash);
        }

        if ($query->update($changes) !== 1) {
            $proposal->refresh();

            if ($proposal->status === ProposalStatus::Executing) {
                throw ProposalStateException::executing($action);
            }

            throw ProposalStateChanged::lostRace($action, $loadedStatus, $proposal->status);
        }

        $proposal->syncOriginal();
    }

    /**
     * The claim matched no row: tell a changed plan apart from a lost race.
     */
    private function explainFailedClaim(Proposal $proposal, string $hash): never
    {
        $current = $this->model()::query()->whereKey($proposal->id)->first();

        if ($current === null || $current->status !== ProposalStatus::Approved) {
            throw ProposalStateChanged::lostRace('executed', ProposalStatus::Approved, $current?->status);
        }

        // Still approved, but the plan being run is not the plan that was
        // approved: send it back for approval (only if nobody else moved it).
        $this->model()::query()
            ->whereKey($current->id)
            ->where('status', ProposalStatus::Approved->value)
            ->where('plan_hash', $current->plan_hash)
            ->update([
                'status' => ProposalStatus::Refined->value,
                'plan_hash' => $current->planObject()->hash(),
                'approved_plan_hash' => null,
                'approved_by' => null,
                'approved_at' => null,
                'approval_instruction' => null,
                'updated_at' => now(),
            ]);

        $proposal->refresh();

        throw ProposalStateException::planChanged();
    }

    private function purge(Proposal $proposal, PurgeReason $reason): void
    {
        $this->purgeHooks->run($proposal->domain, $reason, [
            'proposal_id' => $proposal->id,
            'proposal_uuid' => $proposal->uuid,
            'company_id' => $proposal->company_id,
            'run_id' => $proposal->run_id,
        ]);
    }

    private function run(Proposal $proposal): ?Run
    {
        if ($proposal->run_id === null) {
            return null;
        }

        $query = $this->recorder->runModel()::query()->whereKey($proposal->run_id);

        $proposal->company_id === null
            ? $query->whereNull('company_id')
            : $query->where('company_id', $proposal->company_id);

        return $query->first();
    }

    /**
     * @return class-string<Proposal>
     */
    private function model(): string
    {
        return config('ai.models.proposal', Proposal::class);
    }
}
