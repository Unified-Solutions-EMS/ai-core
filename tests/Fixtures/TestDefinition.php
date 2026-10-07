<?php

declare(strict_types=1);

namespace Unified\AiCore\Tests\Fixtures;

use Unified\AiCore\Agent\AgentContext;
use Unified\AiCore\Agent\AgentDefinition;
use Unified\AiCore\Agent\AgentOutcome;
use Unified\AiCore\Agent\Tool;

class TestDefinition extends AgentDefinition
{
    /** @var list<Tool> */
    public array $toolset;

    public ?string $sop = null;

    public bool $needsSop = false;

    public bool $records = true;

    public bool $directWrites = false;

    public ?string $template = null;

    /** @var array<string, int>|null */
    public ?array $counts = null;

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

    public function allowDirectWrites(): bool
    {
        return $this->directWrites;
    }

    public function domainLabel(): string
    {
        return 'Test dispatch';
    }

    public function summaryTemplate(): string
    {
        return $this->template ?? parent::summaryTemplate();
    }

    public function summaryCounts(AgentOutcome $outcome): array
    {
        return $this->counts ?? parent::summaryCounts($outcome);
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
