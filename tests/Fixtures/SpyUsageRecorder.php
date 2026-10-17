<?php

declare(strict_types=1);

namespace Unified\AiCore\Tests\Fixtures;

use Unified\AiCore\Usage\UsageRecorder;
use Unified\AiCore\Usage\UsageSample;

final class SpyUsageRecorder implements UsageRecorder
{
    /** @var list<UsageSample> */
    public array $samples = [];

    public function record(UsageSample $sample): void
    {
        $this->samples[] = $sample;
    }
}
