<?php

declare(strict_types=1);

namespace Unified\AiCore\Tests\Feature\Usage;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Unified\AiCore\Agent\Agent;
use Unified\AiCore\Agent\AgentContext;
use Unified\AiCore\Agent\AgentOutcome;
use Unified\AiCore\Agent\DryRunContext;
use Unified\AiCore\Client\AiException;
use Unified\AiCore\Ledger\Jobs\RegisterRunWithSso;
use Unified\AiCore\Testing\OpenAiFake;
use Unified\AiCore\Tests\Fixtures\TestContext;
use Unified\AiCore\Tests\Fixtures\TestDefinition;
use Unified\AiCore\Usage\UsageSample;
use Unified\AiCore\Usage\UsageScope;

class AgentUsageTest extends UsageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([RegisterRunWithSso::class]);
    }

    private function runAgent(TestDefinition $definition = new TestDefinition, ?AgentContext $context = null): AgentOutcome
    {
        return app(Agent::class)->run($definition, $context ?? new TestContext, 'Who goes?');
    }

    public function test_every_model_call_in_a_run_is_scoped_from_the_definition_and_context(): void
    {
        OpenAiFake::chat([
            OpenAiFake::toolCalls(['call_1' => ['echo', ['text' => 'hi']]], promptTokens: 40, completionTokens: 8),
            OpenAiFake::message('Medic 4.', 50, 6),
        ]);

        $this->runAgent();

        $this->assertCount(2, $this->spy->samples);

        foreach ($this->spy->samples as $sample) {
            $this->assertSame('test-dispatch', $sample->feature);
            $this->assertSame('chat', $sample->purpose);
            $this->assertSame(['local_company_id' => 7, 'local_user_id' => 3, 'sso_company_id' => 107, 'sso_user_id' => null], $sample->attribution());
            $this->assertNull($sample->surface);
        }

        $this->assertSame([40, 50], array_map(fn (UsageSample $s): int => $s->inputTokens, $this->spy->samples));
        $this->assertSame([], UsageScope::current(), 'the run restores the previous scope');
    }

    public function test_the_definition_can_name_its_feature(): void
    {
        OpenAiFake::chat([OpenAiFake::message('ok')]);

        $this->runAgent(new class extends TestDefinition
        {
            public function usageFeature(): string
            {
                return 'trip-assignment';
            }
        });

        $this->assertSame('trip-assignment', $this->onlySample()->feature);
    }

    public function test_an_outer_feature_and_surface_win_but_the_context_wins_for_ids(): void
    {
        OpenAiFake::chat([OpenAiFake::message('ok')]);

        UsageScope::within(
            ['feature' => 'schedule-assistant', 'surface' => 'queue', 'local_company_id' => 99, 'sso_user_id' => 900],
            fn () => $this->runAgent(),
        );

        $sample = $this->onlySample();
        $this->assertSame('schedule-assistant', $sample->feature);
        $this->assertSame('queue', $sample->surface);
        $this->assertSame(7, $sample->localCompanyId, 'the run context is the authoritative tenant');
        $this->assertSame(900, $sample->ssoUserId, 'outer ids the context lacks are kept');
    }

    public function test_a_null_context_id_keeps_the_outer_one(): void
    {
        OpenAiFake::chat([OpenAiFake::message('ok')]);

        UsageScope::within(['local_user_id' => 12], fn () => $this->runAgent(context: new TestContext(user: null)));

        $this->assertSame(12, $this->onlySample()->localUserId);
    }

    public function test_a_replay_context_is_scoped_from_the_wrapped_context(): void
    {
        OpenAiFake::chat([OpenAiFake::message('ok')]);

        $this->runAgent(context: new DryRunContext(new TestContext(company: 8, companySso: 108, user: 4)));

        $this->assertSame(['local_company_id' => 8, 'local_user_id' => 4, 'sso_company_id' => 108, 'sso_user_id' => null], $this->onlySample()->attribution());
    }

    public function test_a_failed_model_call_in_a_run_is_recorded_and_the_scope_restored(): void
    {
        Http::fake(['*/chat/completions' => Http::response(['error' => ['message' => 'slow down']], 429)]);

        try {
            $this->runAgent();
            $this->fail('Expected AiException');
        } catch (AiException $e) {
            $this->assertSame(AiException::BUSY, $e->reason);
        }

        $this->assertSame('busy', $this->onlySample()->outcome);
        $this->assertSame('test-dispatch', $this->onlySample()->feature);
        $this->assertSame([], UsageScope::current());
    }
}
