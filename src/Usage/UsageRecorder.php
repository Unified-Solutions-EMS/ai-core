<?php

declare(strict_types=1);

namespace Unified\AiCore\Usage;

/**
 * Where OpenAiClient sends one UsageSample per model call. The default
 * (SsoClientUsageRecorder) forwards to sso-client's AiUsage metric; an
 * app without sso-client (SSO itself) binds its own implementation.
 * Implementations may throw: the client swallows every failure.
 */
interface UsageRecorder
{
    public function record(UsageSample $sample): void;
}
