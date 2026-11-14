<?php

declare(strict_types=1);

namespace Unified\AiCore\Tests\Fixtures;

use Unified\AiCore\Agent\AgentContext;
use Unified\AiCore\Agent\RefreshesTools;

/**
 * Offers only `echo` until it has run, then `assign_unit` too, the way a
 * definition grows its tool list when a tool loads more tools.
 */
class LoadsToolsDefinition extends TestDefinition implements RefreshesTools
{
    public int $toolReads = 0;

    public function tools(AgentContext $context): array
    {
        $this->toolReads++;

        return $this->echo->calls === [] ? [$this->echo] : [$this->echo, $this->assign];
    }
}
