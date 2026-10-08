<?php

declare(strict_types=1);

namespace Unified\AiCore\Proposal;

use RuntimeException;

/**
 * A proposal action that its current state does not allow. Map it to
 * HTTP 409 Conflict: the request was valid, the proposal moved on.
 */
class ProposalStateException extends RuntimeException
{
    public const HTTP_STATUS = 409;

    public static function cannot(string $action, ProposalStatus $from): self
    {
        return new self("A proposal cannot be {$action} while it is {$from->value}.");
    }

    public static function planChanged(): self
    {
        return new self('The plan changed after it was approved. It must be approved again before it can run.');
    }

    public static function executing(string $action): self
    {
        return new self("The proposal is being applied right now, so it cannot be {$action}. Wait for it to finish.");
    }
}
