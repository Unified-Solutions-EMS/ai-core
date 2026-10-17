<?php

declare(strict_types=1);

namespace Unified\AiCore\Tests\Feature\Usage;

use Unified\AiCore\Tests\Fixtures\FakeAiUsage;
use Unified\AiCore\Tests\TestCase;
use Unified\AiCore\Usage\SsoClientUsageRecorder;
use Unified\AiCore\Usage\UsageRecorder;
use Unified\AiCore\Usage\UsageSample;

class SsoClientUsageRecorderTest extends TestCase
{
    private function sample(): UsageSample
    {
        return new UsageSample(
            feature: 'run-explain',
            purpose: 'extraction',
            model: 'gpt-5.1',
            inputTokens: 900,
            outputTokens: 40,
            cachedTokens: 512,
            audioSeconds: null,
            durationMs: 1234,
            outcome: 'ok',
            surface: 'web',
            localCompanyId: 7,
            localUserId: 3,
            ssoCompanyId: 107,
            ssoUserId: null,
        );
    }

    public function test_it_is_the_default_singleton_binding(): void
    {
        $this->assertInstanceOf(SsoClientUsageRecorder::class, app(UsageRecorder::class));
        $this->assertSame(app(UsageRecorder::class), app(UsageRecorder::class));
    }

    public function test_it_does_nothing_when_sso_client_is_not_installed(): void
    {
        $this->assertFalse(class_exists(SsoClientUsageRecorder::TARGET), 'sso-client is not a dependency of this package');

        (new SsoClientUsageRecorder)->record($this->sample());

        $this->addToAssertionCount(1);
    }

    public function test_it_forwards_every_field_to_ai_usage_record(): void
    {
        $fake = new FakeAiUsage;
        $this->app->instance(FakeAiUsage::class, $fake);

        (new SsoClientUsageRecorder(FakeAiUsage::class))->record($this->sample());

        $this->assertSame([[
            'feature' => 'run-explain',
            'purpose' => 'extraction',
            'model' => 'gpt-5.1',
            'inputTokens' => 900,
            'outputTokens' => 40,
            'cachedTokens' => 512,
            'audioSeconds' => null,
            'durationMs' => 1234,
            'outcome' => 'ok',
            'surface' => 'web',
            'attribution' => ['local_company_id' => 7, 'local_user_id' => 3, 'sso_company_id' => 107, 'sso_user_id' => null],
        ]], $fake->calls);
    }
}
