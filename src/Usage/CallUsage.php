<?php

declare(strict_types=1);

namespace Unified\AiCore\Usage;

/**
 * What OpenAiClient learns about one call while making it, turned into a
 * UsageSample (with the current UsageScope) once the call ends.
 *
 * @internal
 */
final class CallUsage
{
    public int $inputTokens = 0;

    public int $outputTokens = 0;

    public int $cachedTokens = 0;

    public ?float $audioSeconds = null;

    public ?int $durationMs = null;

    public function __construct(
        public readonly string $purpose,
        public string $model,
    ) {}

    /**
     * @param  array<string, int|string|null>  $scope
     */
    public function toSample(string $outcome, array $scope): UsageSample
    {
        $feature = $scope['feature'] ?? null;
        $surface = $scope['surface'] ?? null;

        return new UsageSample(
            feature: is_string($feature) && $feature !== '' ? $feature : "unscoped-{$this->purpose}",
            purpose: $this->purpose,
            model: $this->model,
            inputTokens: $this->inputTokens,
            outputTokens: $this->outputTokens,
            cachedTokens: $this->cachedTokens,
            audioSeconds: $this->audioSeconds,
            durationMs: $this->durationMs,
            outcome: $outcome,
            surface: is_string($surface) && $surface !== '' ? $surface : null,
            localCompanyId: self::id($scope['local_company_id'] ?? null),
            localUserId: self::id($scope['local_user_id'] ?? null),
            ssoCompanyId: self::id($scope['sso_company_id'] ?? null),
            ssoUserId: self::id($scope['sso_user_id'] ?? null),
        );
    }

    private static function id(int|string|null $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }
}
