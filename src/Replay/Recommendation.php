<?php

declare(strict_types=1);

namespace Unified\AiCore\Replay;

use Unified\AiCore\Agent\AgentOutcome;
use Unified\AiCore\Proposal\PlannedChange;

/**
 * What a run (original or replay) recommended and why, in the shape SSO
 * shows side by side.
 */
final readonly class Recommendation
{
    /**
     * @param  list<PlannedChange>  $changes
     */
    public function __construct(
        public string $explanation,
        public array $changes,
        public string $status,
        public ?string $runId = null,
        public ?string $replayOf = null,
    ) {}

    public static function fromOutcome(AgentOutcome $outcome, ?string $replayOf = null): self
    {
        return new self(
            explanation: (string) $outcome->content,
            changes: $outcome->plannedChanges,
            status: $outcome->status,
            runId: $outcome->run?->uuid,
            replayOf: $replayOf,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'replay_of' => $this->replayOf,
            'status' => $this->status,
            'explanation' => $this->explanation,
            'changes' => array_map(fn (PlannedChange $change): array => $change->toArray(), $this->changes),
        ];
    }
}
