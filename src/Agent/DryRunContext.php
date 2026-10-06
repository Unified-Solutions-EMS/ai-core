<?php

declare(strict_types=1);

namespace Unified\AiCore\Agent;

use Unified\AiCore\Ledger\Run;
use Unified\AiCore\Sop\SopVersion;

/**
 * Wraps a real context for a replay: the agent forces plan mode (every
 * WriteTool is recorded, never executed), records the run with status
 * 'replay' only, never registers it with SSO, and uses the candidate SOP
 * instead of the active one. Tools receive the inner context.
 */
final readonly class DryRunContext implements AgentContext
{
    public function __construct(
        public AgentContext $inner,
        public ?SopVersion $candidateSop = null,
        public ?Run $replayOf = null,
    ) {}

    public function companyId(): ?int
    {
        return $this->inner->companyId();
    }

    public function companySsoId(): int|string|null
    {
        return $this->inner->companySsoId();
    }

    public function userId(): ?int
    {
        return $this->inner->userId();
    }
}
