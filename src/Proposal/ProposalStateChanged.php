<?php

declare(strict_types=1);

namespace Unified\AiCore\Proposal;

/**
 * Another request changed the proposal between this request loading it
 * and writing to it (a lost compare-and-set). Nothing was written; reload
 * and show the person the current state.
 */
class ProposalStateChanged extends ProposalStateException
{
    public static function lostRace(string $action, ProposalStatus $expected, ?ProposalStatus $actual): self
    {
        $now = $actual === null ? 'deleted' : $actual->value;

        return new self("The proposal changed while it was being {$action} (expected {$expected->value}, now {$now}). Reload it and try again.");
    }
}
