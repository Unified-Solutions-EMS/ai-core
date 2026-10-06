<?php

declare(strict_types=1);

namespace Unified\AiCore\Agent;

use Illuminate\Support\Facades\Log;
use Throwable;
use Unified\AiCore\Client\ChatCompletion;
use Unified\AiCore\Client\OpenAiClient;
use Unified\AiCore\Ledger\Run;
use Unified\AiCore\Ledger\RunRecorder;
use Unified\AiCore\Ledger\RunStatus;
use Unified\AiCore\Proposal\PlannedChange;
use Unified\AiCore\Sop\SopClient;
use Unified\AiCore\Sop\SopFrame;
use Unified\AiCore\Sop\SopVersion;
use Unified\AiCore\Support\SsoEndpoint;
use Unified\AiCore\Usage\TokenBudget;

/**
 * The agentic loop: send the conversation and tool definitions to the
 * model, run whatever tools it calls, feed the results back, repeat until
 * it answers in text or runs out of iterations.
 *
 * Every step is written to the conversation store (so a conversation
 * replays across requests) and to the run ledger (when the definition
 * records runs), and is emitted to the caller for live UI updates:
 *   {type: tool_call, name, label}
 *   {type: tool_result, name, ok, ...ui}
 *   {type: message, content}
 *   {type: error, message}
 *
 * In plan mode (and always for a DryRunContext) WriteTools are never
 * executed: the call becomes a PlannedChange on the outcome.
 */
class Agent
{
    public function __construct(
        private readonly OpenAiClient $client,
        private readonly RunRecorder $recorder,
        private readonly TokenBudget $budget,
        private readonly SopClient $sops,
    ) {}

    /**
     * @param  (callable(array<string, mixed>): void)|null  $emit
     */
    public function run(
        AgentDefinition $definition,
        AgentContext $context,
        string $input,
        ?ConversationStore $conversation = null,
        ?callable $emit = null,
        bool $planMode = false,
    ): AgentOutcome {
        $emit ??= static function (array $event): void {};
        $conversation ??= new InMemoryConversation;

        $dryRun = $context instanceof DryRunContext;
        $planMode = $planMode || $dryRun;
        $toolContext = $context instanceof DryRunContext ? $context->inner : $context;

        if (! $this->client->isConfigured()) {
            $emit(['type' => 'error', 'message' => $definition->unavailableMessage()]);

            return new AgentOutcome(AgentOutcome::UNAVAILABLE);
        }

        if ($cap = $this->exceededDailyCap($definition, $toolContext)) {
            $emit(['type' => 'error', 'message' => $definition->capReachedMessage($cap, $toolContext)]);

            return new AgentOutcome(AgentOutcome::CAP_REACHED);
        }

        $sop = $this->resolveSop($definition, $context);

        if ($sop === null && $definition->requiresSop()) {
            $emit(['type' => 'error', 'message' => $definition->missingSopMessage()]);

            return new AgentOutcome(AgentOutcome::SOP_MISSING);
        }

        $userStep = Step::user($input);
        $conversation->record($userStep);

        $systemPrompt = $definition->sopDomain() === null
            ? $definition->systemPrompt($toolContext)
            : SopFrame::wrap($definition->systemPrompt($toolContext), $sop, $definition->hardRules($toolContext));

        $run = $this->startRun($definition, $context, $toolContext, $input, $sop, $systemPrompt, $dryRun);
        $this->recordRunStep($run, $userStep);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ...HistoryRepair::repair(array_map(
                fn (Step $step): array => $step->toMessage(),
                $conversation->recent($definition->historyWindow()),
            )),
        ];

        $tools = $this->indexTools($definition->tools($toolContext));
        $planned = [];
        $promptTokens = 0;
        $completionTokens = 0;

        try {
            for ($iteration = 0; $iteration < $definition->maxIterations(); $iteration++) {
                $completion = $this->client->chat(
                    messages: $messages,
                    tools: $this->toolDefinitions($tools),
                    model: $definition->model(),
                    reasoningEffort: $definition->reasoningEffort(),
                );

                $promptTokens += $completion->promptTokens;
                $completionTokens += $completion->completionTokens;

                if (! $completion->hasToolCalls()) {
                    return $this->answer($definition, $conversation, $run, $completion, $planned, $promptTokens, $completionTokens, $sop, $emit);
                }

                $toolCalls = array_map(fn ($call): array => $call->toArray(), $completion->toolCalls);

                $assistantStep = Step::assistant($completion->content, $toolCalls, $completion->promptTokens, $completion->completionTokens);
                $conversation->record($assistantStep);
                $this->recordRunStep($run, $assistantStep);

                $messages[] = [
                    'role' => 'assistant',
                    'content' => $completion->content ?? '',
                    'tool_calls' => $toolCalls,
                ];

                foreach ($completion->toolCalls as $call) {
                    $args = $call->args();

                    $emit(['type' => 'tool_call', 'name' => $call->name, 'label' => $definition->toolLabel($call->name, $args)]);

                    $result = $this->executeTool($definition, $tools[$call->name] ?? null, $call->name, $args, $toolContext, $planMode, $planned);

                    $toolStep = Step::tool($call->id, $call->name, $args, $result->payload);
                    $conversation->record($toolStep);
                    $this->recordRunStep($run, $toolStep);

                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $call->id,
                        'content' => $toolStep->content,
                    ];

                    $emit([
                        'type' => 'tool_result',
                        'name' => $call->name,
                        'ok' => ! $result->failed(),
                        ...($result->ui ?? []),
                    ]);
                }
            }
        } catch (Throwable $e) {
            if ($run !== null) {
                $this->recorder->fail($run, $e, $promptTokens, $completionTokens);
            }

            throw $e;
        }

        $emit(['type' => 'error', 'message' => $definition->iterationLimitMessage()]);

        $outcome = new AgentOutcome(AgentOutcome::ITERATION_LIMIT, null, $planned, $promptTokens, $completionTokens, $run, $sop);

        if ($run !== null) {
            $this->recorder->finish($run, RunStatus::Incomplete, null, $this->recommendation($planned, null), $promptTokens, $completionTokens, $definition->indexSummary($outcome, $planned));
        }

        return $outcome;
    }

    /**
     * @param  list<PlannedChange>  $planned
     * @param  callable(array<string, mixed>): void  $emit
     */
    private function answer(
        AgentDefinition $definition,
        ConversationStore $conversation,
        ?Run $run,
        ChatCompletion $completion,
        array $planned,
        int $promptTokens,
        int $completionTokens,
        ?SopVersion $sop,
        callable $emit,
    ): AgentOutcome {
        $content = (string) $completion->content;

        $step = Step::assistant($content, null, $completion->promptTokens, $completion->completionTokens);
        $conversation->record($step);
        $this->recordRunStep($run, $step);

        $emit(['type' => 'message', 'content' => $content]);

        $outcome = new AgentOutcome(AgentOutcome::ANSWERED, $content, $planned, $promptTokens, $completionTokens, $run, $sop);

        if ($run !== null) {
            $this->recorder->finish($run, RunStatus::Completed, $content, $this->recommendation($planned, $content), $promptTokens, $completionTokens, $definition->indexSummary($outcome, $planned));
        }

        return $outcome;
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  list<PlannedChange>  $planned
     */
    private function executeTool(
        AgentDefinition $definition,
        ?Tool $tool,
        string $name,
        array $args,
        AgentContext $context,
        bool $planMode,
        array &$planned,
    ): ToolResult {
        if ($tool === null) {
            return ToolResult::error("Unknown tool '{$name}'.");
        }

        try {
            if ($planMode && $tool instanceof WriteTool) {
                $change = $tool instanceof DescribesChange
                    ? $tool->describeChange($args, $context)
                    : PlannedChange::fromToolCall(SsoEndpoint::appSlug(), $name, $args);

                $planned[] = $change;

                return new ToolResult(
                    [
                        'planned' => true,
                        'change_number' => count($planned),
                        'note' => 'Recorded as a proposed change. Nothing was written; it runs only if a person approves the plan. Continue as if it will be applied.',
                    ],
                    ['planned_change' => $change->toArray()],
                );
            }

            return $tool->execute($args, $context);
        } catch (ToolFailure $e) {
            return ToolResult::error($e->getMessage());
        } catch (Throwable $e) {
            Log::warning('AI tool failed', ['domain' => $definition->domain(), 'tool' => $name, 'error' => $e->getMessage()]);

            return ToolResult::error($definition->toolErrorMessage($name, $e));
        }
    }

    /**
     * @param  list<Tool>  $tools
     * @return array<string, Tool>
     */
    private function indexTools(array $tools): array
    {
        $indexed = [];

        foreach ($tools as $tool) {
            $indexed[$tool->name()] = $tool;
        }

        return $indexed;
    }

    /**
     * @param  array<string, Tool>  $tools
     * @return list<array<string, mixed>>
     */
    private function toolDefinitions(array $tools): array
    {
        return array_values(array_map(fn (Tool $tool): array => [
            'type' => 'function',
            'function' => [
                'name' => $tool->name(),
                ...$tool->definition(),
            ],
        ], $tools));
    }

    private function exceededDailyCap(AgentDefinition $definition, AgentContext $context): ?int
    {
        $cap = $definition->dailyTokenCap($context);

        if ($cap <= 0) {
            return null;
        }

        $spent = $definition->tokensSpentToday($context)
            ?? ($this->recorder->enabled() ? $this->budget->spentToday($definition->domain(), $context->companyId()) : 0);

        return $spent >= $cap ? $cap : null;
    }

    private function resolveSop(AgentDefinition $definition, AgentContext $context): ?SopVersion
    {
        $domain = $definition->sopDomain();

        if ($domain === null) {
            return null;
        }

        if ($context instanceof DryRunContext && $context->candidateSop !== null) {
            return $context->candidateSop;
        }

        $companySsoId = $context->companySsoId();

        return $companySsoId === null ? null : $this->sops->active($domain, $companySsoId);
    }

    private function startRun(
        AgentDefinition $definition,
        AgentContext $context,
        AgentContext $toolContext,
        string $input,
        ?SopVersion $sop,
        string $systemPrompt,
        bool $dryRun,
    ): ?Run {
        if (! $definition->recordsRuns() || ! $this->recorder->enabled()) {
            return null;
        }

        $snapshot = ['input' => $input, ...$definition->inputSnapshot($toolContext, $input)];

        if ($context instanceof DryRunContext) {
            $snapshot['replay_of'] = $context->replayOf?->uuid;
            $snapshot['candidate_sop'] = $context->candidateSop?->isCandidate() ? $context->candidateSop->body : null;
        }

        return $this->recorder->start(
            domain: $definition->domain(),
            context: $toolContext,
            inputSnapshot: $snapshot,
            model: $definition->model(),
            sop: $sop,
            promptHash: hash('sha256', $systemPrompt),
            replay: $dryRun,
        );
    }

    private function recordRunStep(?Run $run, Step $step): void
    {
        if ($run !== null) {
            $this->recorder->step($run, $step);
        }
    }

    /**
     * @param  list<PlannedChange>  $planned
     * @return array<string, mixed>
     */
    private function recommendation(array $planned, ?string $content): array
    {
        return [
            'content' => $content,
            'changes' => array_map(fn (PlannedChange $change): array => $change->toArray(), $planned),
        ];
    }
}
