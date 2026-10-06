<?php

declare(strict_types=1);

namespace Unified\AiCore\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * SSO base URL, key and request defaults, resolved the way the
 * sso-client metrics/security jobs resolve them: config('ai.sso.*')
 * first, then the sso-client config, with CORE_APP_API_KEY as the only
 * credential (DEV_GUIDELINES §21).
 */
final class SsoEndpoint
{
    public static function baseUrl(): ?string
    {
        $base = (string) (config('ai.sso.base_url') ?: config('sso.base_url', ''));

        return $base === '' ? null : rtrim($base, '/');
    }

    public static function url(string $path): ?string
    {
        $base = self::baseUrl();

        return $base === null ? null : $base.'/'.ltrim($path, '/');
    }

    public static function token(): string
    {
        return (string) (config('ai.sso.token') ?: config('metrics.token') ?: config('app.core_api_key') ?: config('sso.core_api_key', ''));
    }

    public static function appSlug(): string
    {
        return (string) (config('ai.app_slug') ?: config('sso.app_slug') ?: config('metrics.app_key', ''));
    }

    public static function request(): PendingRequest
    {
        return Http::timeout((int) config('ai.sso.timeout', 5))
            ->withToken(self::token())
            ->acceptJson()
            ->withOptions(['verify' => (bool) config('ai.sso.verify_ssl', true)]);
    }
}
