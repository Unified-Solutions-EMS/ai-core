<?php

declare(strict_types=1);

namespace Unified\AiCore\Agent;

/**
 * Implemented by an agent definition that counts its own token spend
 * (e.g. from chat history tables). Required when the definition has a
 * daily cap but does not record runs, since the cap is otherwise summed
 * from the run ledger.
 */
interface TracksTokenSpend
{
    /**
     * Today's prompt + completion tokens for the context's company (or
     * the staff pool when it has none).
     */
    public function tokensSpentToday(AgentContext $context): int;
}
