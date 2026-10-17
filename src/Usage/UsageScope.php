<?php

declare(strict_types=1);

namespace Unified\AiCore\Usage;

use InvalidArgumentException;

/**
 * Who and what the next model calls are for: the product feature, the
 * local and SSO company/user, and the surface (web, mobile, api, queue).
 * OpenAiClient stamps every usage sample with the current scope; a call
 * made with no feature in scope is recorded as `unscoped-{purpose}`.
 *
 * Agent::run() scopes its own calls (feature from the definition,
 * attribution from the context). An app calling OpenAiClient directly
 * wraps the call, or sets the scope once at the job or controller
 * boundary:
 *
 *   UsageScope::within(['feature' => 'run-explain', 'local_company_id' => $companyId,
 *       'local_user_id' => $userId, 'surface' => 'web'], fn () => $client->respond(...));
 *
 * within() layers onto the current scope (inner keys override outer
 * ones) and restores the previous scope on exit, exception or not. The
 * state lives in a container instance bound as scoped, so it resets
 * between Octane requests and queued jobs.
 */
final class UsageScope
{
    public const KEYS = ['feature', 'local_company_id', 'local_user_id', 'sso_company_id', 'sso_user_id', 'surface'];

    /** @var array<string, int|string|null> */
    private array $scope = [];

    /**
     * @template T
     *
     * @param  array<string, int|string|null>  $scope
     * @param  callable(): T  $fn
     * @return T
     */
    public static function within(array $scope, callable $fn): mixed
    {
        $instance = self::instance();
        $previous = $instance->scope;
        $instance->scope = array_merge($previous, self::validated($scope));

        try {
            return $fn();
        } finally {
            $instance->scope = $previous;
        }
    }

    /**
     * Replace the current scope.
     *
     * @param  array<string, int|string|null>  $scope
     */
    public static function set(array $scope): void
    {
        self::instance()->scope = self::validated($scope);
    }

    public static function clear(): void
    {
        self::instance()->scope = [];
    }

    /**
     * @return array<string, int|string|null>
     */
    public static function current(): array
    {
        return self::instance()->scope;
    }

    private static function instance(): self
    {
        return app(self::class);
    }

    /**
     * @param  array<string, int|string|null>  $scope
     * @return array<string, int|string|null>
     */
    private static function validated(array $scope): array
    {
        $unknown = array_diff(array_keys($scope), self::KEYS);

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown usage scope keys: '.implode(', ', $unknown).'. Allowed: '.implode(', ', self::KEYS).'.');
        }

        return $scope;
    }
}
