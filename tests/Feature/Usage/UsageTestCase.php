<?php

declare(strict_types=1);

namespace Unified\AiCore\Tests\Feature\Usage;

use Unified\AiCore\Client\OpenAiClient;
use Unified\AiCore\Tests\Fixtures\SpyUsageRecorder;
use Unified\AiCore\Tests\TestCase;
use Unified\AiCore\Usage\UsageRecorder;
use Unified\AiCore\Usage\UsageSample;

abstract class UsageTestCase extends TestCase
{
    protected SpyUsageRecorder $spy;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.usage.enabled', true);
        $this->spy = new SpyUsageRecorder;
        $this->app->instance(UsageRecorder::class, $this->spy);
    }

    protected function client(): OpenAiClient
    {
        return app(OpenAiClient::class);
    }

    protected function onlySample(): UsageSample
    {
        $this->assertCount(1, $this->spy->samples);

        return $this->spy->samples[0];
    }
}
