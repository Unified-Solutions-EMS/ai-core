<?php

declare(strict_types=1);

namespace Unified\AiCore\Phi;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Registry of PHI purge hooks per AI domain. Apps register in a service
 * provider and the proposal service (or the app itself) runs them after
 * execution or abandonment.
 *
 * Every hook runs even when an earlier one throws: a purge that stops at
 * the first failure leaves the rest of the PHI behind.
 */
class PurgeHooks
{
    /** @var array<string, list<PurgeHook|Closure|class-string<PurgeHook>>> */
    private array $hooks = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  PurgeHook|Closure(PurgeReason, array<string, mixed>): void|class-string<PurgeHook>  $hook
     */
    public function register(string $domain, PurgeHook|Closure|string $hook): void
    {
        $this->hooks[$domain][] = $hook;
    }

    public function has(string $domain): bool
    {
        return ($this->hooks[$domain] ?? []) !== [];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return int hooks that ran without throwing
     */
    public function run(string $domain, PurgeReason $reason, array $context = []): int
    {
        $ran = 0;

        foreach ($this->hooks[$domain] ?? [] as $hook) {
            try {
                if ($hook instanceof Closure) {
                    $hook($reason, $context);
                } else {
                    $instance = is_string($hook) ? $this->container->make($hook) : $hook;
                    $instance->purge($reason, $context);
                }

                $ran++;
            } catch (Throwable $e) {
                Log::error('AI purge hook failed', [
                    'domain' => $domain,
                    'reason' => $reason->value,
                    'hook' => is_object($hook) ? $hook::class : $hook,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $ran;
    }
}
