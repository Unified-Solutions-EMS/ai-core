<?php

declare(strict_types=1);

namespace Unified\AiCore\Replay;

use Unified\AiCore\Ledger\Run;

/**
 * An agent that can re-run a recorded run against a candidate SOP body
 * without writing anything (SSO's "Test against this run"). Apps expose
 * this behind POST /api/internal/ai/replay, filtering the run by the
 * authoritative company id from the request.
 */
interface ReplayableAgent
{
    public function replay(Run $run, string $candidateSopBody): Recommendation;
}
