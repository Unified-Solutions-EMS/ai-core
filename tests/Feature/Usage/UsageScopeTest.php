<?php

declare(strict_types=1);

namespace Unified\AiCore\Tests\Feature\Usage;

use InvalidArgumentException;
use RuntimeException;
use Unified\AiCore\Tests\TestCase;
use Unified\AiCore\Usage\UsageScope;

class UsageScopeTest extends TestCase
{
    public function test_within_layers_onto_the_outer_scope_and_restores_it(): void
    {
        $seen = UsageScope::within(['feature' => 'outer', 'local_company_id' => 7], function (): array {
            $inner = UsageScope::within(['feature' => 'inner', 'surface' => 'queue'], fn (): array => UsageScope::current());

            return [$inner, UsageScope::current()];
        });

        $this->assertSame(['feature' => 'inner', 'local_company_id' => 7, 'surface' => 'queue'], $seen[0]);
        $this->assertSame(['feature' => 'outer', 'local_company_id' => 7], $seen[1]);
        $this->assertSame([], UsageScope::current());
    }

    public function test_within_restores_the_previous_scope_when_the_callback_throws(): void
    {
        UsageScope::set(['feature' => 'job']);

        $thrown = null;

        try {
            UsageScope::within(['feature' => 'nested'], function (): void {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $thrown);
        $this->assertSame('boom', $thrown->getMessage());

        $this->assertSame(['feature' => 'job'], UsageScope::current());
    }

    public function test_within_returns_the_callback_result(): void
    {
        $this->assertSame(42, UsageScope::within(['feature' => 'x'], fn (): int => 42));
    }

    public function test_set_replaces_and_clear_empties(): void
    {
        UsageScope::set(['feature' => 'a', 'local_user_id' => 3]);
        UsageScope::set(['feature' => 'b']);
        $this->assertSame(['feature' => 'b'], UsageScope::current());

        UsageScope::clear();
        $this->assertSame([], UsageScope::current());
    }

    public function test_unknown_keys_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('company_id');

        UsageScope::within(['company_id' => 7], fn () => null);
    }

    public function test_scope_is_reset_for_each_request_or_job(): void
    {
        UsageScope::set(['feature' => 'leaked']);

        $this->app->forgetScopedInstances();

        $this->assertSame([], UsageScope::current());
    }
}
