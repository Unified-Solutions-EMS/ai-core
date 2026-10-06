<?php

declare(strict_types=1);

namespace Unified\AiCore\Proposal;

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
 * Approval binds to the plan hash. Refining the plan produces a new hash
 * and clears the approval; execution re-checks the hash and aborts if the
 * plan changed. Execution is claimed with a conditional update so two
 * overlapping requests cannot both run the same plan.
 *
 * The executor is app-supplied: nothing here writes app data.
 */
class ProposalService
{
    public function __construct(
        private readonly RunRecorder $recorder,
        private readonly PurgeHooks $purgeHooks,
    ) {}

    public function draft(string $domain, Plan $plan, ?int $companyId, ?int $createdBy, ?Run $run = null): Proposal
    {
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
        $this->guard($proposal, ProposalStatus::Refined, 'refined');

        $proposal->forceFill([
            'status' => ProposalStatus::Refined,
            'plan' => $plan->toArray(),
            'plan_hash' => $plan->hash(),
            'approved_plan_hash' => null,
            'approved_by' => null,
            'approved_at' => null,
            'approval_instruction' => null,
        ])->save();

        return $proposal;
    }

    /**
     * Record the explicit "Approve and apply" click. Pass the hash the
     * person was shown ($expectedHash) so an approval of a stale plan is
     * refused rather than applied to a newer one.
     */
    public function approve(Proposal $proposal, int $userId, string $instruction, ?string $expectedHash = null): Proposal
    {
        $this->guard($proposal, ProposalStatus::Approved, 'approved');

        if ($expectedHash !== null && ! hash_equals($proposal->plan_hash, $expectedHash)) {
            throw ProposalStateException::planChanged();
        }

        $proposal->forceFill([
            'status' => ProposalStatus::Approved,
            'approved_plan_hash' => $proposal->plan_hash,
            'approved_by' => $userId,
            'approved_at' => now(),
            'approval_instruction' => $instruction,
        ])->save();

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
     * @param  callable(PlannedChange, int, Proposal): mixed  $executor  returns a per-change result (JSON-serialisable)
     */
    public function execute(Proposal $proposal, callable $executor, bool $stopOnFailure = false): Proposal
    {
        if ($proposal->status !== ProposalStatus::Approved) {
            throw ProposalStateException::cannot('executed', $proposal->status);
        }

        $plan = $proposal->planObject();

        if ($proposal->approved_plan_hash === null
            || ! hash_equals($proposal->approved_plan_hash, $proposal->plan_hash)
            || ! hash_equals($proposal->plan_hash, $plan->hash())) {
            $proposal->forceFill([
                'status' => ProposalStatus::Refined,
                'plan_hash' => $plan->hash(),
                'approved_plan_hash' => null,
                'approved_by' => null,
                'approved_at' => null,
                'approval_instruction' => null,
            ])->save();

            throw ProposalStateException::planChanged();
        }

        $claimed = $this->model()::query()
            ->whereKey($proposal->id)
            ->where('status', ProposalStatus::Approved->value)
            ->update(['status' => ProposalStatus::Executing->value, 'updated_at' => now()]);

        if ($claimed !== 1) {
            throw ProposalStateException::cannot('executed', $proposal->fresh()->status ?? $proposal->status);
        }

        $proposal->status = ProposalStatus::Executing;
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

        $proposal->forceFill([
            'status' => $failed ? ProposalStatus::Failed : ProposalStatus::Executed,
            'executed_at' => now(),
            'results' => $results,
        ])->save();

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
        $this->guard($proposal, ProposalStatus::Verified, 'verified');

        $proposal->forceFill([
            'status' => ProposalStatus::Verified,
            'verified_by' => $userId,
            'verified_at' => now(),
        ])->save();

        return $proposal;
    }

    public function reject(Proposal $proposal, ?int $userId, string $reason): Proposal
    {
        $this->guard($proposal, ProposalStatus::Rejected, 'rejected');

        $proposal->forceFill([
            'status' => ProposalStatus::Rejected,
            'rejected_by' => $userId,
            'rejected_reason' => $reason,
        ])->save();

        $this->purge($proposal, PurgeReason::Abandoned);

        return $proposal;
    }

    private function guard(Proposal $proposal, ProposalStatus $next, string $action): void
    {
        if (! $proposal->status->canTransitionTo($next)) {
            throw ProposalStateException::cannot($action, $proposal->status);
        }
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
