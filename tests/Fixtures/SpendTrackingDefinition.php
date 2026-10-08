<?php

declare(strict_types=1);

namespace Unified\AiCore\Tests\Fixtures;

use Unified\AiCore\Agent\AgentContext;
use Unified\AiCore\Agent\TracksTokenSpend;

class SpendTrackingDefinition extends TestDefinition implements TracksTokenSpend
{
    public int $spent = 0;

    public function tokensSpentToday(AgentContext $context): int
    {
        return $this->spent;
    }
}
