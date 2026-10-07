<?php

declare(strict_types=1);

namespace Unified\AiCore\Tests\Feature\Agent;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Unified\AiCore\Agent\Agent;
use Unified\AiCore\Agent\AgentContext;
use Unified\AiCore\Agent\AgentOutcome;
use Unified\AiCore\Agent\HistoryRepair;
use Unified\AiCore\Agent\InMemoryConversation;
use Unified\AiCore\Agent\Step;
use Unified\AiCore\Agent\ToolResult;
use Unified\AiCore\Client\AiException;
use Unified\AiCore\Ledger\Jobs\RegisterRunWithSso;
use Unified\AiCore\Ledger\Run;
use Unified\AiCore\Ledger\RunStatus;
use Unified\AiCore\Proposal\Plan;
use Unified\AiCore\Proposal\ProposalService;
use Unified\AiCore\Testing\OpenAiFake;
use Unified\AiCore\Tests\Fixtures\EchoTool;
use Unified\AiCore\Tests\Fixtures\SpendTrackingDefinition;
use Unified\AiCore\Tests\Fixtures\TestContext;
use Unified\AiCore\Tests\Fixtures\TestDefinition;
use Unified\AiCore\Tests\TestCase;

class AgentTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([RegisterRunWithSso::class]);
    }

    private function runAgent(TestDefinition $definition, string $input = 'Who goes?', ?InMemoryConversation $conversation = null, bool $planMode = false, mixed $context = null): AgentOutcome
    {
        return app(Agent::class)->run(
            $definition,
            $context ?? new TestContext,
            $input,
            $conversation,
            function (array $event): void {
                $this->events[] = $event;
            },
            $planMode,
        );
    }

    public function test_text_answer_is_emitted_recorded_and_ledgered(): void
    {
        OpenAiFake::chat([OpenAiFake::message('Medic 4.', 30, 6)]);
        $conversation = new InMemoryConversation;

        $outcome = $this->runAgent(new TestDefinition, conversation: $conversation);

        $this->assertSame(AgentOutcome::ANSWERED, $outcome->status);
        $this->assertSame('Medic 4.', $outcome->content);
        $this->assertSame([['type' => 'message', 'content' => 'Medic 4.']], $this->events);
        $this->assertSame(['user', 'assistant'], array_map(fn (Step $s): string => $s->role, $conversation->steps()));

        $run = Run::query()->sole();
        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertSame('test.dispatch', $run->domain);
        $this->assertSame(7, $run->company_id);
        $this->assertSame('107', $run->company_sso_id);
        $this->assertSame(3, $run->user_id);
        $this->assertSame('testapp', $run->app_slug);
        $this->assertSame(['input' => 'Who goes?', 'writes' => 'planned', 'refines_proposal' => null], $run->input_snapshot);
        $this->assertSame('Medic 4.', $run->explanation);
        $this->assertSame(36, $run->totalTokens());
        $this->assertSame(64, strlen((string) $run->prompt_hash));
        $this->assertSame(['user', 'assistant'], $run->steps()->pluck('role')->all());

        Bus::assertDispatched(RegisterRunWithSso::class, fn (RegisterRunWithSso $job): bool => $job->payload['run_id'] === $run->uuid
            && $job->payload['outcome'] === 'completed'
            && $job->payload['summary'] === 'Test dispatch: 0 proposed changes, completed'
            && $job->payload['company_sso_id'] === '107');
    }

    public function test_tool_loop_executes_tools_emits_steps_and_feeds_results_back(): void
    {
        $definition = new TestDefinition;
        OpenAiFake::chat([
            OpenAiFake::toolCalls(['c1' => ['echo', ['text' => 'hello']], 'c2' => ['nope', []]]),
            OpenAiFake::message('done'),
        ]);

        $outcome = $this->runAgent($definition);

        $this->assertTrue($outcome->answered());
        $this->assertSame(20, $outcome->promptTokens);
        $this->assertSame([
            ['type' => 'tool_call', 'name' => 'echo', 'label' => 'Running echo'],
            ['type' => 'tool_result', 'name' => 'echo', 'ok' => true, 'rows' => 1],
            ['type' => 'tool_call', 'name' => 'nope', 'label' => 'Running nope'],
            ['type' => 'tool_result', 'name' => 'nope', 'ok' => false],
            ['type' => 'message', 'content' => 'done'],
        ], $this->events);

        $this->assertInstanceOf(TestContext::class, $definition->echo->calls[0]['context']);

        $second = OpenAiFake::sentChatBodies()[1]['messages'];
        $this->assertSame('system', $second[0]['role']);
        $this->assertSame('assistant', $second[2]['role']);
        $this->assertSame('c1', $second[3]['tool_call_id']);
        $this->assertSame('{"echo":"hello"}', $second[3]['content']);
        $this->assertSame('{"error":"Unknown tool \'nope\'."}', $second[4]['content']);

        $steps = Run::query()->sole()->steps;
        $this->assertSame(['user', 'assistant', 'tool', 'tool', 'assistant'], $steps->pluck('role')->all());
        $this->assertSame(['text' => 'hello'], $steps[2]->arguments);
        $this->assertSame(['echo' => 'hello'], $steps[2]->result);
        $this->assertSame([1, 2, 3, 4, 5], $steps->pluck('seq')->all());
    }

    public function test_tool_failures_become_model_readable_errors(): void
    {
        OpenAiFake::chat([
            OpenAiFake::toolCalls(['c1' => ['echo', ['text' => 'refuse']], 'c2' => ['echo', ['text' => 'explode']]]),
            OpenAiFake::message('sorry'),
        ]);

        $this->runAgent(new TestDefinition);

        $messages = OpenAiFake::sentChatBodies()[1]['messages'];
        $this->assertSame('{"error":"text may not be \"refuse\""}', $messages[3]['content']);
        $this->assertSame('{"error":"The echo tool failed: boom"}', $messages[4]['content']);
    }

    public function test_iteration_limit_emits_an_error_and_marks_the_run_incomplete(): void
    {
        config()->set('ai.agent.max_iterations', 2);
        OpenAiFake::chat([
            OpenAiFake::toolCalls(['c1' => ['echo', ['text' => 'a']]]),
            OpenAiFake::toolCalls(['c2' => ['echo', ['text' => 'b']]]),
        ]);

        $outcome = $this->runAgent(new TestDefinition);

        $this->assertSame(AgentOutcome::ITERATION_LIMIT, $outcome->status);
        $this->assertSame('error', end($this->events)['type']);
        $this->assertSame(RunStatus::Incomplete, Run::query()->sole()->status);
    }

    public function test_daily_cap_from_the_ledger_blocks_before_anything_is_sent(): void
    {
        config()->set('ai.token_caps', ['default' => 0, 'test.dispatch' => 100]);
        Run::query()->create(['domain' => 'test.dispatch', 'company_id' => 7, 'status' => 'completed', 'prompt_tokens' => 90, 'completion_tokens' => 20]);
        Run::query()->create(['domain' => 'test.dispatch', 'company_id' => 8, 'status' => 'completed', 'prompt_tokens' => 900, 'completion_tokens' => 0]);
        Http::fake();

        $outcome = $this->runAgent(new TestDefinition);

        $this->assertSame(AgentOutcome::CAP_REACHED, $outcome->status);
        $this->assertStringContainsString('(100 tokens)', $this->events[0]['message']);
        Http::assertNothingSent();

        $this->events = [];
        OpenAiFake::chat([OpenAiFake::message('fine')]);
        $other = $this->runAgent(new TestDefinition, context: new TestContext(company: 9));
        $this->assertTrue($other->answered());
    }

    public function test_definition_can_supply_its_own_spend(): void
    {
        config()->set('ai.token_caps', ['default' => 50]);
        $definition = new SpendTrackingDefinition;
        $definition->records = false;
        $definition->spent = 50;
        Http::fake();

        $this->assertSame(AgentOutcome::CAP_REACHED, $this->runAgent($definition)->status);
        Http::assertNothingSent();
    }

    public function test_a_cap_without_any_spend_source_refuses_to_run(): void
    {
        $definition = new TestDefinition;
        $definition->records = false;
        Http::fake();

        try {
            $this->runAgent($definition);
            $this->fail('Expected LogicException');
        } catch (LogicException $e) {
            $this->assertStringContainsString('TracksTokenSpend', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_disabled_ledger_also_needs_a_spend_source(): void
    {
        config()->set('ai.ledger.enabled', false);

        $this->expectException(LogicException::class);
        $this->runAgent(new TestDefinition);
    }

    public function test_no_spend_source_is_needed_when_the_cap_is_off(): void
    {
        config()->set('ai.token_caps', ['default' => 0]);
        $definition = new TestDefinition;
        $definition->records = false;
        OpenAiFake::chat([OpenAiFake::message('ok')]);

        $this->assertTrue($this->runAgent($definition)->answered());
    }

    public function test_replays_do_not_count_against_the_daily_cap(): void
    {
        config()->set('ai.token_caps', ['default' => 100]);
        Run::query()->create(['domain' => 'test.dispatch', 'company_id' => 7, 'status' => 'replay', 'prompt_tokens' => 5000]);
        OpenAiFake::chat([OpenAiFake::message('ok')]);

        $this->assertTrue($this->runAgent(new TestDefinition)->answered());
    }

    public function test_unconfigured_client_reports_unavailable_without_recording_anything(): void
    {
        config()->set('ai.openai.api_key', '');
        $conversation = new InMemoryConversation;

        $outcome = $this->runAgent(new TestDefinition, conversation: $conversation);

        $this->assertSame(AgentOutcome::UNAVAILABLE, $outcome->status);
        $this->assertSame([], $conversation->steps());
        $this->assertSame(0, Run::query()->count());
    }

    public function test_history_is_repaired_before_replay(): void
    {
        $conversation = new InMemoryConversation([
            Step::tool('stranded', 'echo', [], ['echo' => 'x']),
            Step::user('first'),
            Step::assistant(null, [['id' => 'lost', 'type' => 'function', 'function' => ['name' => 'echo', 'arguments' => '{}']]]),
        ]);
        OpenAiFake::chat([OpenAiFake::message('ok')]);

        $this->runAgent(new TestDefinition, 'second', $conversation);

        $messages = OpenAiFake::sentChatBodies()[0]['messages'];
        $this->assertSame(['system', 'user', 'assistant', 'tool', 'user'], array_column($messages, 'role'));
        $this->assertSame('lost', $messages[3]['tool_call_id']);
        $this->assertSame(json_encode(['error' => HistoryRepair::INTERRUPTED]), $messages[3]['content']);
    }

    public function test_history_window_limits_replayed_steps(): void
    {
        config()->set('ai.agent.history_window', 2);
        $conversation = new InMemoryConversation([Step::user('a'), Step::assistant('b'), Step::user('c'), Step::assistant('d')]);
        OpenAiFake::chat([OpenAiFake::message('ok')]);

        $this->runAgent(new TestDefinition, 'e', $conversation);

        $this->assertSame(['d', 'e'], array_column(array_slice(OpenAiFake::sentChatBodies()[0]['messages'], 1), 'content'));
    }

    public function test_plan_mode_records_write_tools_instead_of_executing_them(): void
    {
        $definition = new TestDefinition;
        OpenAiFake::chat([
            OpenAiFake::toolCalls(['c1' => ['assign_unit', ['trip' => 12, 'unit' => 'M4']], 'c2' => ['echo', ['text' => 'read']]]),
            OpenAiFake::message('Assign M4 to trip 12.'),
        ]);

        $outcome = $this->runAgent($definition, planMode: true);

        $this->assertSame(0, $definition->assign->executed);
        $this->assertCount(1, $definition->echo->calls, 'read tools still run in plan mode');
        $this->assertCount(1, $outcome->plannedChanges);
        $this->assertSame('trip:12', $outcome->plannedChanges[0]->entity);
        $this->assertSame(['unit' => 'M4'], $outcome->plannedChanges[0]->after);
        $this->assertSame('trip:12', $this->events[1]['planned_change']['entity']);

        $toolMessage = OpenAiFake::sentChatBodies()[1]['messages'][3];
        $this->assertTrue(json_decode($toolMessage['content'], true)['planned']);

        $run = Run::query()->sole();
        $this->assertSame('trip:12', $run->recommendation['changes'][0]['entity']);
        $this->assertSame('Test dispatch: 1 proposed changes', $run->summary);
        $this->assertSame('planned', $run->input_snapshot['writes']);

        $plan = $outcome->plan('Dispatch plan');
        $this->assertSame('Dispatch plan', $plan->summary);
        $this->assertCount(1, $plan->changes);
    }

    public function test_write_tools_are_intercepted_by_default_without_plan_mode(): void
    {
        $definition = new TestDefinition;
        OpenAiFake::chat([OpenAiFake::toolCalls(['c1' => ['assign_unit', ['trip' => 1, 'unit' => 'M1']]]), OpenAiFake::message('done')]);

        $outcome = $this->runAgent($definition);

        $this->assertSame(0, $definition->assign->executed);
        $this->assertCount(1, $outcome->plannedChanges);
    }

    public function test_write_tools_execute_only_when_the_definition_opts_in(): void
    {
        $definition = new TestDefinition;
        $definition->directWrites = true;
        OpenAiFake::chat([OpenAiFake::toolCalls(['c1' => ['assign_unit', ['trip' => 1, 'unit' => 'M1']]]), OpenAiFake::message('done')]);

        $outcome = $this->runAgent($definition);

        $this->assertSame(1, $definition->assign->executed);
        $this->assertSame([], $outcome->plannedChanges);
        $this->assertSame('direct', Run::query()->sole()->input_snapshot['writes']);
    }

    public function test_plan_mode_overrides_an_opt_in_to_direct_writes(): void
    {
        $definition = new TestDefinition;
        $definition->directWrites = true;
        OpenAiFake::chat([OpenAiFake::toolCalls(['c1' => ['assign_unit', ['trip' => 1, 'unit' => 'M1']]]), OpenAiFake::message('done')]);

        $this->runAgent($definition, planMode: true);

        $this->assertSame(0, $definition->assign->executed);
    }

    public function test_direct_writes_are_refused_on_a_proposal(): void
    {
        $definition = new TestDefinition;
        $definition->directWrites = true;
        $proposal = app(ProposalService::class)->draft('test.dispatch', new Plan('p', []), 7, 3);
        Http::fake();

        $this->expectException(LogicException::class);
        app(Agent::class)->run($definition, new TestContext, 'refine', proposal: $proposal);
    }

    public function test_a_run_refining_a_proposal_records_it(): void
    {
        $proposal = app(ProposalService::class)->draft('test.dispatch', new Plan('p', []), 7, 3);
        OpenAiFake::chat([OpenAiFake::message('ok')]);

        app(Agent::class)->run(new TestDefinition, new TestContext, 'refine', proposal: $proposal);

        $this->assertSame($proposal->uuid, Run::query()->sole()->input_snapshot['refines_proposal']);
    }

    public function test_a_direct_write_run_cannot_become_a_proposal(): void
    {
        $definition = new TestDefinition;
        $definition->directWrites = true;
        OpenAiFake::chat([OpenAiFake::message('ok')]);
        $outcome = $this->runAgent($definition);

        $this->expectException(LogicException::class);
        app(ProposalService::class)->draft('test.dispatch', new Plan('p', []), 7, 3, $outcome->run);
    }

    public function test_run_summary_is_built_from_the_template_and_counts(): void
    {
        $definition = new TestDefinition;
        $definition->template = '{trips} trips, {assigned} assigned, {unassignable} unassignable';
        $definition->counts = ['trips' => 3, 'assigned' => 3, 'unassignable' => 0];
        OpenAiFake::chat([OpenAiFake::message('ok')]);

        $this->runAgent($definition);

        $this->assertSame('Test dispatch: 3 trips, 3 assigned, 0 unassignable', Run::query()->sole()->summary);
        Bus::assertDispatched(RegisterRunWithSso::class, fn (RegisterRunWithSso $job): bool => $job->payload['summary'] === 'Test dispatch: 3 trips, 3 assigned, 0 unassignable, completed');
    }

    public function test_an_unsafe_summary_template_is_refused_before_anything_is_sent(): void
    {
        $definition = new TestDefinition;
        $definition->template = 'Patient {name} is "John Doe"';
        Http::fake();

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->runAgent($definition);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_definition_can_opt_out_of_the_ledger(): void
    {
        $definition = new SpendTrackingDefinition;
        $definition->records = false;
        OpenAiFake::chat([OpenAiFake::message('ok')]);

        $this->runAgent($definition);

        $this->assertSame(0, Run::query()->count());
        Bus::assertNotDispatched(RegisterRunWithSso::class);
    }

    public function test_client_failure_marks_the_run_failed_and_rethrows(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'down']], 503)]);

        try {
            $this->runAgent(new TestDefinition);
            $this->fail('Expected AiException');
        } catch (AiException) {
        }

        $run = Run::query()->sole();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame(AiException::class, $run->outcome['error']);
    }

    public function test_sop_frames_the_prompt_and_is_recorded_on_the_run(): void
    {
        $definition = new TestDefinition;
        $definition->sop = 'test.dispatch';
        Http::fake([
            'sso.test/*' => Http::response(['data' => ['id' => 55, 'version' => 3, 'body' => 'Prefer ALS units for chest pain.']]),
            '*/chat/completions' => Http::response(OpenAiFake::message('ok')),
        ]);

        $outcome = $this->runAgent($definition);

        $this->assertSame(55, $outcome->sop?->id);
        $this->assertSame(55, Run::query()->sole()->sop_version_id);

        $system = OpenAiFake::sentChatBodies()[0]['messages'][0]['content'];
        $this->assertStringStartsWith('You dispatch for company 7.', $system);
        $this->assertStringContainsString('Prefer ALS units for chest pain.', $system);
        $this->assertStringContainsString('- Never assign an out-of-service unit.', $system);
        $this->assertStringContainsString('version 3', $system);
    }

    public function test_required_sop_missing_refuses_to_run(): void
    {
        $definition = new TestDefinition;
        $definition->sop = 'test.dispatch';
        $definition->needsSop = true;
        Http::fake(['sso.test/*' => Http::response([], 404)]);

        $outcome = $this->runAgent($definition);

        $this->assertSame(AgentOutcome::SOP_MISSING, $outcome->status);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'chat/completions'));
    }

    public function test_tool_exceptions_never_escape_the_loop(): void
    {
        $definition = new TestDefinition;
        $definition->toolset[] = new class extends EchoTool
        {
            public function name(): string
            {
                return 'broken';
            }

            public function execute(array $args, AgentContext $context): ToolResult
            {
                throw new RuntimeException('db gone');
            }
        };
        OpenAiFake::chat([OpenAiFake::toolCalls(['c1' => ['broken', []]]), OpenAiFake::message('ok')]);

        $this->assertTrue($this->runAgent($definition)->answered());
        $this->assertFalse($this->events[1]['ok']);
    }
}
