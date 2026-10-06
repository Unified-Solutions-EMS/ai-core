<?php

declare(strict_types=1);

namespace Unified\AiCore\Agent;

use Unified\AiCore\Ledger\Run;
use Unified\AiCore\Proposal\Plan;
use Unified\AiCore\Proposal\PlannedChange;
use Unified\AiCore\Sop\SopVersion;

/**
 * What one agent run produced.
 */
final readonly class AgentOutcome
{
    public const ANSWERED = 'answered';

    public const ITERATION_LIMIT = 'iteration_limit';

    public const CAP_REACHED = 'cap_reached';

    public const UNAVAILABLE = 'unavailable';

    public const SOP_MISSING = 'sop_missing';

    /**
     * @param  list<PlannedChange>  $plannedChanges
     */
    public function __construct(
        public string $status,
        public ?string $content = null,
        public array $plannedChanges = [],
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public ?Run $run = null,
        public ?SopVersion $sop = null,
    ) {}

    public function answered(): bool
    {
        return $this->status === self::ANSWERED;
    }

    /**
     * The planned changes as a Plan for ProposalService::draft().
     */
    public function plan(?string $summary = null): Plan
    {
        return new Plan($summary ?? (string) $this->content, $this->plannedChanges);
    }
}
