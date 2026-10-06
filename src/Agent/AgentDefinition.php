<?php

declare(strict_types=1);

namespace Unified\AiCore\Agent;

use Unified\AiCore\Proposal\PlannedChange;
use Unified\AiCore\Usage\TokenBudget;

/**
 * Everything app-specific about an agent: its domain, system prompt,
 * tools and limits. The loop itself (Agent) is shared.
 *
 * Hooks with defaults read config('ai.*'); override the ones the app
 * already configures elsewhere.
 */
abstract class AgentDefinition
{
    /**
     * Stable id for the kind of work, e.g. "cad.dispatch",
     * "analytics.chat". Keys the token cap, the ledger and the SOP.
     */
    abstract public function domain(): string;

    abstract public function systemPrompt(AgentContext $context): string;

    /**
     * @return list<Tool>
     */
    abstract public function tools(AgentContext $context): array;

    public function model(): string
    {
        return (string) config('ai.openai.models.chat');
    }

    public function reasoningEffort(): ?string
    {
        $effort = config('ai.openai.reasoning_effort');

        return is_string($effort) ? $effort : null;
    }

    public function maxIterations(): int
    {
        return max(1, (int) config('ai.agent.max_iterations', 10));
    }

    public function historyWindow(): int
    {
        return max(1, (int) config('ai.agent.history_window', 40));
    }

    /**
     * 0 disables the cap.
     */
    public function dailyTokenCap(AgentContext $context): int
    {
        return app(TokenBudget::class)->cap($this->domain());
    }

    /**
     * Today's spend for the cap. Null uses the run ledger; apps that keep
     * token counts elsewhere (chat history tables) return their own sum.
     */
    public function tokensSpentToday(AgentContext $context): ?int
    {
        return null;
    }

    /**
     * Whether runs go to ai_runs / ai_run_steps. Conversational agents
     * that already persist every turn may opt out.
     */
    public function recordsRuns(): bool
    {
        return true;
    }

    /**
     * The SOP domain to frame the prompt with, or null for an agent that
     * is not SOP-driven.
     */
    public function sopDomain(): ?string
    {
        return null;
    }

    /**
     * Refuse to run when no active SOP exists.
     */
    public function requiresSop(): bool
    {
        return false;
    }

    /**
     * Constraints the app enforces in code whatever the SOP says, listed
     * in the frame so the model does not propose what will be refused.
     *
     * @return list<string>
     */
    public function hardRules(AgentContext $context): array
    {
        return [];
    }

    /**
     * Extra input recorded with the run (the candidates, settings and
     * state the agent saw) so "why" and replay are grounded in the same
     * facts. Stays in the app's own ledger.
     *
     * @return array<string, mixed>
     */
    public function inputSnapshot(AgentContext $context, string $input): array
    {
        return [];
    }

    /**
     * One PHI-free line for SSO's run index. Never include names,
     * addresses or free text from the input.
     *
     * @param  list<PlannedChange>  $plannedChanges
     */
    public function indexSummary(AgentOutcome $outcome, array $plannedChanges): string
    {
        $count = count($plannedChanges);

        return $count > 0
            ? sprintf('Proposed %d %s', $count, $count === 1 ? 'change' : 'changes')
            : 'Answered';
    }

    /**
     * Short human label for the activity line while a tool runs.
     *
     * @param  array<string, mixed>  $args
     */
    public function toolLabel(string $toolName, array $args): string
    {
        return $toolName;
    }

    public function capReachedMessage(int $cap, AgentContext $context): string
    {
        return "The daily AI usage limit ({$cap} tokens) has been reached. Try again tomorrow.";
    }

    public function iterationLimitMessage(): string
    {
        return 'The assistant hit its per-message tool limit before finishing. Try a more specific question.';
    }

    public function unavailableMessage(): string
    {
        return 'The assistant is not available right now.';
    }

    public function missingSopMessage(): string
    {
        return 'There is no active SOP for this yet, so the assistant cannot make a recommendation.';
    }

    public function toolErrorMessage(string $toolName, \Throwable $error): string
    {
        return "The {$toolName} tool failed: {$error->getMessage()}";
    }
}
