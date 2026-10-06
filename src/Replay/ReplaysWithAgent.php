<?php

declare(strict_types=1);

namespace Unified\AiCore\Replay;

use Unified\AiCore\Agent\Agent;
use Unified\AiCore\Agent\AgentContext;
use Unified\AiCore\Agent\AgentDefinition;
use Unified\AiCore\Agent\DryRunContext;
use Unified\AiCore\Ledger\Run;
use Unified\AiCore\Sop\SopVersion;

/**
 * Default ReplayableAgent implementation: rebuild the run's context, wrap
 * it in a DryRunContext carrying the candidate SOP, and run the same
 * input again in plan mode. Tools see the same live data the app serves
 * today; agents whose answer depends on a moment in time should read the
 * recorded input_snapshot in their tools when the context is a replay.
 *
 * @phpstan-require-implements ReplayableAgent
 */
trait ReplaysWithAgent
{
    abstract protected function replayDefinition(): AgentDefinition;

    /**
     * Rebuild the context the run was made under, from the run's own
     * company_id/user_id (the authoritative source), never from input.
     */
    abstract protected function replayContext(Run $run): AgentContext;

    public function replay(Run $run, string $candidateSopBody): Recommendation
    {
        $definition = $this->replayDefinition();

        $context = new DryRunContext(
            inner: $this->replayContext($run),
            candidateSop: SopVersion::candidate($definition->sopDomain() ?? $definition->domain(), $candidateSopBody),
            replayOf: $run,
        );

        $outcome = app(Agent::class)->run($definition, $context, (string) ($run->input_snapshot['input'] ?? ''));

        return Recommendation::fromOutcome($outcome, $run->uuid);
    }
}
