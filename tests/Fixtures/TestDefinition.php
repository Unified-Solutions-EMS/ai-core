<?php

declare(strict_types=1);

namespace Unified\AiCore\Tests\Fixtures;

use Unified\AiCore\Agent\AgentContext;
use Unified\AiCore\Agent\AgentDefinition;
use Unified\AiCore\Agent\Tool;

class TestDefinition extends AgentDefinition
{
    /** @var list<Tool> */
    public array $toolset;

    public ?string $sop = null;

    public bool $needsSop = false;

    public bool $records = true;

    public ?int $spent = null;

    public function __construct(
        public EchoTool $echo = new EchoTool,
        public AssignUnitTool $assign = new AssignUnitTool,
    ) {
        $this->toolset = [$this->echo, $this->assign];
    }

    public function domain(): string
    {
        return 'test.dispatch';
    }

    public function systemPrompt(AgentContext $context): string
    {
        return "You dispatch for company {$context->companyId()}.";
    }

    public function tools(AgentContext $context): array
    {
        return $this->toolset;
    }

    public function sopDomain(): ?string
    {
        return $this->sop;
    }

    public function requiresSop(): bool
    {
        return $this->needsSop;
    }

    public function recordsRuns(): bool
    {
        return $this->records;
    }

    public function tokensSpentToday(AgentContext $context): ?int
    {
        return $this->spent;
    }

    public function hardRules(AgentContext $context): array
    {
        return ['Never assign an out-of-service unit.'];
    }

    public function toolLabel(string $toolName, array $args): string
    {
        return "Running {$toolName}";
    }
}
