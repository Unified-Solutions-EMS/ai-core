<?php

declare(strict_types=1);

namespace Unified\AiCore\Tests\Fixtures;

use Unified\AiCore\Agent\AgentContext;
use Unified\AiCore\Agent\DescribesChange;
use Unified\AiCore\Agent\ToolResult;
use Unified\AiCore\Agent\WriteTool;
use Unified\AiCore\Proposal\PlannedChange;

class AssignUnitTool implements DescribesChange, WriteTool
{
    public int $executed = 0;

    public function name(): string
    {
        return 'assign_unit';
    }

    public function definition(): array
    {
        return [
            'description' => 'Assign a unit to a trip.',
            'parameters' => ['type' => 'object', 'properties' => ['trip' => ['type' => 'integer'], 'unit' => ['type' => 'string']]],
        ];
    }

    public function execute(array $args, AgentContext $context): ToolResult
    {
        $this->executed++;

        return new ToolResult(['assigned' => true]);
    }

    public function describeChange(array $args, AgentContext $context): PlannedChange
    {
        return new PlannedChange('cad', "trip:{$args['trip']}", null, ['unit' => $args['unit']], 'closest available', PlannedChange::RISK_LOW);
    }
}
