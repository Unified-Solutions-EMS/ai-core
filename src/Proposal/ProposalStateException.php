<?php

declare(strict_types=1);

namespace Unified\AiCore\Proposal;

use RuntimeException;

class ProposalStateException extends RuntimeException
{
    public static function cannot(string $action, ProposalStatus $from): self
    {
        return new self("A proposal cannot be {$action} while it is {$from->value}.");
    }

    public static function planChanged(): self
    {
        return new self('The plan changed after it was approved. It must be approved again before it can run.');
    }
}
