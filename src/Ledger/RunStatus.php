<?php

declare(strict_types=1);

namespace Unified\AiCore\Ledger;

enum RunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Incomplete = 'incomplete';
    case Failed = 'failed';
    case Replay = 'replay';
}
