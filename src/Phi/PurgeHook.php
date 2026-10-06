<?php

declare(strict_types=1);

namespace Unified\AiCore\Phi;

/**
 * Deletes PHI an AI flow left behind (uploads, transcripts, quotes) once
 * the work it fed is executed or abandoned.
 */
interface PurgeHook
{
    /**
     * @param  array<string, mixed>  $context  ids the hook needs (proposal id, run id, company id, ...)
     */
    public function purge(PurgeReason $reason, array $context): void;
}
