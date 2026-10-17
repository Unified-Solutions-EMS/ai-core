<?php

declare(strict_types=1);

namespace Unified\AiCore\Usage;

/**
 * Forwards each sample to unified/sso-client's AiUsage metric, which
 * shapes the `ai.request` event, translates local ids to SSO ids and
 * queues the send. ai-core does not depend on sso-client: when it is not
 * installed this recorder does nothing.
 */
class SsoClientUsageRecorder implements UsageRecorder
{
    public const TARGET = 'Unified\\SsoClient\\Metrics\\AiUsage';

    public function __construct(private readonly string $target = self::TARGET) {}

    public function record(UsageSample $sample): void
    {
        if (! class_exists($this->target)) {
            return;
        }

        app($this->target)->record(
            feature: $sample->feature,
            purpose: $sample->purpose,
            model: $sample->model,
            inputTokens: $sample->inputTokens,
            outputTokens: $sample->outputTokens,
            cachedTokens: $sample->cachedTokens,
            audioSeconds: $sample->audioSeconds,
            durationMs: $sample->durationMs,
            outcome: $sample->outcome,
            surface: $sample->surface,
            attribution: $sample->attribution(),
        );
    }
}
