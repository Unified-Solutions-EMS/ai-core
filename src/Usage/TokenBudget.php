<?php

declare(strict_types=1);

namespace Unified\AiCore\Usage;

use Unified\AiCore\Ledger\Run;
use Unified\AiCore\Ledger\RunStatus;

/**
 * Daily token cap per company per domain. Caps come from
 * config('ai.token_caps') keyed by domain (domains contain dots, so the
 * array is read whole rather than through a dotted config path), falling
 * back to 'default'. Spend is summed from the run ledger unless the
 * agent definition implements TracksTokenSpend. Replays (SSO's "test
 * against this run") are staff work and never count against the
 * agency's cap.
 */
class TokenBudget
{
    public function cap(string $domain): int
    {
        $caps = (array) config('ai.token_caps', []);

        return (int) ($caps[$domain] ?? $caps['default'] ?? 0);
    }

    public function spentToday(string $domain, ?int $companyId): int
    {
        /** @var class-string<Run> $model */
        $model = config('ai.models.run', Run::class);

        $query = $model::query()
            ->where('domain', $domain)
            ->where('status', '!=', RunStatus::Replay->value)
            ->where('created_at', '>=', now()->startOfDay());

        $companyId === null ? $query->whereNull('company_id') : $query->where('company_id', $companyId);

        return (int) $query->sum($query->getQuery()->raw('prompt_tokens + completion_tokens'));
    }
}
