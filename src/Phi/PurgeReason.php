<?php

declare(strict_types=1);

namespace Unified\AiCore\Phi;

enum PurgeReason: string
{
    case Executed = 'executed';
    case Abandoned = 'abandoned';
}
