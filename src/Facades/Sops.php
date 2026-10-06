<?php

declare(strict_types=1);

namespace Unified\AiCore\Facades;

use Illuminate\Support\Facades\Facade;
use Unified\AiCore\Sop\SopClient;

/**
 * @method static \Unified\AiCore\Sop\SopVersion|null active(string $domain, int|string $companySsoId)
 * @method static void forget(string $domain, int|string $companySsoId)
 *
 * @see SopClient
 */
class Sops extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SopClient::class;
    }
}
