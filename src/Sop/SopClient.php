<?php

declare(strict_types=1);

namespace Unified\AiCore\Sop;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;
use Unified\AiCore\Support\SsoEndpoint;

/**
 * Reads the active SOP version for a company + domain from SSO
 * (GET /api/internal/sops/{company}/{domain}/active, CORE_APP_API_KEY).
 *
 * Found and not-found answers are cached (default 5 minutes); SSO
 * invalidates early through the sop.activated webhook, which apps route
 * to forget(). Errors are not cached and read as "no SOP": the agent's
 * hard rules live in code and hold either way, and a definition that
 * cannot run without an SOP declares requiresSop().
 */
class SopClient
{
    private const MISSING = '__none__';

    public function active(string $domain, int|string $companySsoId): ?SopVersion
    {
        $key = $this->cacheKey($domain, $companySsoId);
        $cached = Cache::get($key);

        if ($cached === self::MISSING) {
            return null;
        }

        if (is_array($cached)) {
            return SopVersion::fromArray($cached, $domain);
        }

        $url = SsoEndpoint::url('/api/internal/sops/'.rawurlencode((string) $companySsoId).'/'.rawurlencode($domain).'/active');
        $token = SsoEndpoint::token();

        if ($url === null || $token === '') {
            return null;
        }

        try {
            $response = SsoEndpoint::request()->get($url);
        } catch (Throwable $e) {
            Log::warning('SOP lookup failed', ['domain' => $domain, 'company_sso_id' => $companySsoId, 'error' => $e->getMessage()]);

            return null;
        }

        $ttl = (int) config('ai.sops.cache_seconds', 300);

        if ($response->status() === 404) {
            Cache::put($key, self::MISSING, $ttl);

            return null;
        }

        $data = $response->json('data') ?? $response->json();

        if (! $response->successful() || ! is_array($data) || ! isset($data['body'])) {
            Log::warning('SOP lookup returned an unusable response', ['domain' => $domain, 'status' => $response->status()]);

            return null;
        }

        $version = SopVersion::fromArray($data, $domain);
        Cache::put($key, $version->toArray(), $ttl);

        return $version;
    }

    public function forget(string $domain, int|string $companySsoId): void
    {
        Cache::forget($this->cacheKey($domain, $companySsoId));
    }

    private function cacheKey(string $domain, int|string $companySsoId): string
    {
        return "ai:sop:{$companySsoId}:{$domain}";
    }
}
