<?php

declare(strict_types=1);

namespace Unified\AiCore\Usage;

final class NullUsageRecorder implements UsageRecorder
{
    public function record(UsageSample $sample): void {}
}
