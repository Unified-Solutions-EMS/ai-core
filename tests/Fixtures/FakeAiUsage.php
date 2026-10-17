<?php

declare(strict_types=1);

namespace Unified\AiCore\Tests\Fixtures;

/**
 * Stands in for sso-client's Unified\SsoClient\Metrics\AiUsage, with the
 * same record() signature, so the forwarding contract is tested without
 * installing sso-client.
 */
final class FakeAiUsage
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /**
     * @param  array{local_company_id?: int|null, local_user_id?: int|null, sso_company_id?: int|null, sso_user_id?: int|null}  $attribution
     */
    public function record(
        string $feature,
        string $purpose,
        ?string $model,
        int $inputTokens = 0,
        int $outputTokens = 0,
        int $cachedTokens = 0,
        ?float $audioSeconds = null,
        ?int $durationMs = null,
        string $outcome = 'ok',
        ?string $surface = null,
        array $attribution = [],
    ): void {
        $this->calls[] = get_defined_vars();
    }
}
