<?php

declare(strict_types=1);

namespace Unified\AiCore\Ledger;

use Illuminate\Database\Eloquent\Model;
use Throwable;
use Unified\AiCore\Agent\AgentContext;
use Unified\AiCore\Agent\Step;
use Unified\AiCore\Ledger\Jobs\RegisterRunWithSso;
use Unified\AiCore\Sop\SopVersion;
use Unified\AiCore\Support\SsoEndpoint;

/**
 * Writes the run ledger. The agent loop calls start/step/finish itself;
 * apps call markApproved/markExecuted (or let ProposalService do it).
 * Every non-replay state change re-sends the PHI-free index row to SSO.
 */
class RunRecorder
{
    /** @var array<int, int> */
    private array $sequence = [];

    public function enabled(): bool
    {
        return (bool) config('ai.ledger.enabled', true);
    }

    /**
     * @param  array<string, mixed>  $inputSnapshot
     */
    public function start(
        string $domain,
        AgentContext $context,
        array $inputSnapshot,
        string $model,
        ?SopVersion $sop,
        string $promptHash,
        bool $replay = false,
    ): Run {
        $companySsoId = $context->companySsoId();

        /** @var Run $run */
        $run = $this->runModel()::query()->create([
            'app_slug' => SsoEndpoint::appSlug() ?: null,
            'domain' => $domain,
            'company_id' => $context->companyId(),
            'company_sso_id' => $companySsoId === null ? null : (string) $companySsoId,
            'user_id' => $context->userId(),
            'sop_version_id' => is_numeric($sop?->id) ? (int) $sop->id : null,
            'model' => $model,
            'input_snapshot' => $inputSnapshot,
            'prompt_hash' => $promptHash,
            'status' => $replay ? RunStatus::Replay : RunStatus::Running,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
        ]);

        $this->sequence[$run->id] = 0;

        return $run;
    }

    public function step(Run $run, Step $step): RunStep
    {
        $seq = $this->sequence[$run->id] = ($this->sequence[$run->id] ?? (int) $run->steps()->max('seq')) + 1;

        /** @var RunStep $model */
        $model = $this->stepModel()::query()->create([
            'run_id' => $run->id,
            'seq' => $seq,
            'role' => $step->role,
            'tool_name' => $step->toolName,
            'arguments' => $step->role === Step::TOOL
                ? $step->arguments
                : ($step->toolCalls !== null ? ['tool_calls' => $step->toolCalls] : null),
            'result' => $step->role === Step::TOOL ? $step->result : ['content' => $step->content],
            'tokens' => $step->promptTokens + $step->completionTokens,
        ]);

        return $model;
    }

    /**
     * @param  array<string, mixed>|null  $recommendation
     */
    public function finish(
        Run $run,
        RunStatus $status,
        ?string $explanation,
        ?array $recommendation,
        int $promptTokens,
        int $completionTokens,
        string $summary,
    ): Run {
        $run->forceFill([
            'status' => $run->status === RunStatus::Replay ? RunStatus::Replay : $status,
            'explanation' => $explanation,
            'recommendation' => $recommendation,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'summary' => mb_substr($summary, 0, 255),
        ])->save();

        unset($this->sequence[$run->id]);
        $this->register($run);

        return $run;
    }

    public function fail(Run $run, Throwable $error, int $promptTokens, int $completionTokens): Run
    {
        $run->forceFill([
            'status' => $run->status === RunStatus::Replay ? RunStatus::Replay : RunStatus::Failed,
            'outcome' => ['error' => $error::class, 'message' => mb_substr($error->getMessage(), 0, 500)],
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'summary' => 'Did not finish',
        ])->save();

        unset($this->sequence[$run->id]);
        $this->register($run);

        return $run;
    }

    public function attachProposal(Run $run, int $proposalId): Run
    {
        $run->forceFill(['proposal_id' => $proposalId])->save();

        return $run;
    }

    public function markApproved(Run $run, int $userId): Run
    {
        $run->forceFill(['approved_by' => $userId, 'approved_at' => now()])->save();
        $this->register($run);

        return $run;
    }

    /**
     * @param  array<string, mixed>  $outcome
     */
    public function markExecuted(Run $run, array $outcome): Run
    {
        $run->forceFill(['executed_at' => now(), 'outcome' => $outcome])->save();
        $this->register($run);

        return $run;
    }

    /**
     * Queue the PHI-free index row. Replays are never indexed.
     */
    public function register(Run $run): void
    {
        if ($run->status === RunStatus::Replay || ! config('ai.ledger.register_with_sso', true)) {
            return;
        }

        $job = new RegisterRunWithSso([
            'app_slug' => (string) ($run->app_slug ?: SsoEndpoint::appSlug()),
            'domain' => $run->domain,
            'run_id' => $run->uuid,
            'company_sso_id' => $run->company_sso_id,
            'sop_version_id' => $run->sop_version_id,
            'summary' => (string) ($run->summary ?? ''),
            'outcome' => $run->indexOutcome(),
            'approved_by_sso_id' => $this->userSsoId($run->approved_by),
            'created_at' => $run->created_at?->toIso8601String(),
        ]);

        if ($connection = config('ai.ledger.queue_connection')) {
            $job->onConnection((string) $connection);
        }

        if ($queue = config('ai.ledger.queue')) {
            $job->onQueue((string) $queue);
        }

        dispatch($job);
    }

    /**
     * @return class-string<Run>
     */
    public function runModel(): string
    {
        return config('ai.models.run', Run::class);
    }

    /**
     * @return class-string<RunStep>
     */
    private function stepModel(): string
    {
        return config('ai.models.run_step', RunStep::class);
    }

    private function userSsoId(?int $userId): int|string|null
    {
        if ($userId === null) {
            return null;
        }

        $model = (string) config('ai.models.user');

        if (! is_subclass_of($model, Model::class)) {
            return null;
        }

        try {
            $value = $model::query()->whereKey($userId)->value((string) config('ai.models.user_sso_id_column', 'sso_id'));
        } catch (Throwable) {
            return null;
        }

        return is_int($value) || is_string($value) ? $value : null;
    }
}
