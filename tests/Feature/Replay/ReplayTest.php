<?php

declare(strict_types=1);

namespace Unified\AiCore\Tests\Feature\Replay;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Unified\AiCore\Agent\Agent;
use Unified\AiCore\Agent\AgentContext;
use Unified\AiCore\Agent\AgentDefinition;
use Unified\AiCore\Agent\DryRunContext;
use Unified\AiCore\Ledger\Jobs\RegisterRunWithSso;
use Unified\AiCore\Ledger\Run;
use Unified\AiCore\Ledger\RunStatus;
use Unified\AiCore\Replay\ReplayableAgent;
use Unified\AiCore\Replay\ReplaysWithAgent;
use Unified\AiCore\Sop\SopVersion;
use Unified\AiCore\Testing\OpenAiFake;
use Unified\AiCore\Tests\Fixtures\TestContext;
use Unified\AiCore\Tests\Fixtures\TestDefinition;
use Unified\AiCore\Tests\TestCase;

class ReplayTest extends TestCase
{
    public function test_replay_runs_the_recorded_input_in_plan_mode_with_the_candidate_sop(): void
    {
        Bus::fake([RegisterRunWithSso::class]);

        $definition = new TestDefinition;
        $definition->sop = 'test.dispatch';

        $original = Run::query()->create([
            'domain' => 'test.dispatch',
            'company_id' => 7,
            'user_id' => 3,
            'status' => 'completed',
            'input_snapshot' => ['input' => 'Trip 12 needs a unit'],
        ]);

        OpenAiFake::chat([
            OpenAiFake::toolCalls(['c1' => ['assign_unit', ['trip' => 12, 'unit' => 'M9']]]),
            OpenAiFake::message('M9 per the new SOP.'),
        ]);

        $agent = new class($definition) implements ReplayableAgent
        {
            use ReplaysWithAgent;

            public function __construct(private readonly TestDefinition $definition) {}

            protected function replayDefinition(): AgentDefinition
            {
                return $this->definition;
            }

            protected function replayContext(Run $run): AgentContext
            {
                return new TestContext(company: $run->company_id, user: $run->user_id);
            }
        };

        $recommendation = $agent->replay($original, 'Prefer the unit with the most fuel.');

        $this->assertSame(0, $definition->assign->executed, 'write tools never execute in a replay');
        $this->assertSame('M9 per the new SOP.', $recommendation->explanation);
        $this->assertSame(['unit' => 'M9'], $recommendation->changes[0]->after);
        $this->assertSame($original->uuid, $recommendation->replayOf);

        $replay = Run::query()->where('uuid', $recommendation->runId)->sole();
        $this->assertSame(RunStatus::Replay, $replay->status);
        $this->assertSame($original->uuid, $replay->input_snapshot['replay_of']);
        $this->assertSame('Prefer the unit with the most fuel.', $replay->input_snapshot['candidate_sop']);
        $this->assertNull($replay->sop_version_id);
        $this->assertSame(RunStatus::Completed, $original->fresh()->status, 'the original run is untouched');

        $system = OpenAiFake::sentChatBodies()[0]['messages'][0]['content'];
        $this->assertStringContainsString('Prefer the unit with the most fuel.', $system);
        $this->assertStringContainsString('candidate version', $system);

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/sops/'));
        Bus::assertNotDispatched(RegisterRunWithSso::class);
        $this->assertSame(['run_id', 'replay_of', 'status', 'explanation', 'changes'], array_keys($recommendation->toArray()));
    }

    public function test_replaying_an_agent_without_an_sop_domain_throws(): void
    {
        $definition = new TestDefinition;
        $run = Run::query()->create(['domain' => 'test.dispatch', 'company_id' => 7, 'status' => 'completed', 'input_snapshot' => ['input' => 'x']]);
        Http::fake();

        $agent = new class($definition) implements ReplayableAgent
        {
            use ReplaysWithAgent;

            public function __construct(private readonly TestDefinition $definition) {}

            protected function replayDefinition(): AgentDefinition
            {
                return $this->definition;
            }

            protected function replayContext(Run $run): AgentContext
            {
                return new TestContext;
            }
        };

        try {
            $agent->replay($run, 'Candidate text');
            $this->fail('A replay ignored its candidate SOP');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('not SOP-driven', $e->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame(1, Run::query()->count());
    }

    public function test_the_agent_itself_refuses_a_candidate_sop_it_would_ignore(): void
    {
        Http::fake();

        $this->expectException(\LogicException::class);
        app(Agent::class)->run(
            new TestDefinition,
            new DryRunContext(new TestContext, SopVersion::candidate('test.dispatch', 'Candidate')),
            'x',
        );
    }
}
