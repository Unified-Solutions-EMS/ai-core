<?php

declare(strict_types=1);

namespace Unified\AiCore\Tests;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use Unified\AiCore\AiCoreServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [AiCoreServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('cache.default', 'array');

        $app['config']->set('ai.openai.api_key', 'sk-test');
        $app['config']->set('ai.openai.base_url', 'https://api.openai.test/v1');
        $app['config']->set('ai.app_slug', 'testapp');
        $app['config']->set('ai.sso.base_url', 'https://sso.test');
        $app['config']->set('ai.sso.token', 'core-key');
        $app['config']->set('ai.models.user', Fixtures\User::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }
}
