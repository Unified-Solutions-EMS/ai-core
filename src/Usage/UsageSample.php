<?php

declare(strict_types=1);

namespace Unified\AiCore\Usage;

/**
 * One model call, as the `ai.request` usage metric records it. PHI-free
 * by construction: ids, counts and the model name only, never prompt
 * text, transcripts or file names.
 */
final readonly class UsageSample
{
    public const OUTCOME_OK = 'ok';

    public const OUTCOME_ERROR = 'error';

    public const OUTCOME_TIMEOUT = 'timeout';

    public const OUTCOME_BUSY = 'busy';

    public const OUTCOME_REFUSED = 'refused';

    public const OUTCOME_REJECTED = 'rejected';

    public function __construct(
        public string $feature,
        public string $purpose,
        public ?string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cachedTokens = 0,
        public ?float $audioSeconds = null,
        public ?int $durationMs = null,
        public string $outcome = self::OUTCOME_OK,
        public ?string $surface = null,
        public ?int $localCompanyId = null,
        public ?int $localUserId = null,
        public ?int $ssoCompanyId = null,
        public ?int $ssoUserId = null,
    ) {}

    /**
     * The attribution array sso-client's AiUsage::record() takes.
     *
     * @return array{local_company_id: int|null, local_user_id: int|null, sso_company_id: int|null, sso_user_id: int|null}
     */
    public function attribution(): array
    {
        return [
            'local_company_id' => $this->localCompanyId,
            'local_user_id' => $this->localUserId,
            'sso_company_id' => $this->ssoCompanyId,
            'sso_user_id' => $this->ssoUserId,
        ];
    }
}
