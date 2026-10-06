<?php

declare(strict_types=1);

namespace Unified\AiCore\Agent;

use Unified\AiCore\Proposal\PlannedChange;

/**
 * Optional companion to WriteTool: turn a call's arguments into the plan
 * entry a person approves (target, entity, before, after, why, risk).
 * Reads only; must not write.
 */
interface DescribesChange
{
    /**
     * @param  array<string, mixed>  $args
     */
    public function describeChange(array $args, AgentContext $context): PlannedChange;
}
