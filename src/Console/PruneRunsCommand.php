<?php

declare(strict_types=1);

namespace Unified\AiCore\Console;

use Illuminate\Console\Command;
use Unified\AiCore\Ledger\Run;
use Unified\AiCore\Ledger\RunStep;

/**
 * Deletes ledger runs (and their steps) older than the retention window.
 * Runs inputs can hold PHI, so the ledger should not grow forever.
 */
class PruneRunsCommand extends Command
{
    protected $signature = 'ai:prune-runs {--days= : Delete runs older than this many days (default: ai.ledger.retention_days)}';

    protected $description = 'Delete AI run ledger entries older than the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('ai.ledger.retention_days', 365));

        if ($days < 1) {
            $this->error('--days must be at least 1.');

            return self::FAILURE;
        }

        /** @var class-string<Run> $runModel */
        $runModel = config('ai.models.run', Run::class);
        /** @var class-string<RunStep> $stepModel */
        $stepModel = config('ai.models.run_step', RunStep::class);

        $cutoff = now()->subDays($days);
        $deleted = 0;

        $runModel::query()
            ->where('created_at', '<', $cutoff)
            ->select('id')
            ->chunkById(500, function ($runs) use ($stepModel, $runModel, &$deleted): void {
                $ids = $runs->pluck('id')->all();
                $stepModel::query()->whereIn('run_id', $ids)->delete();
                $deleted += $runModel::query()->whereIn('id', $ids)->delete();
            });

        $this->info("Deleted {$deleted} AI runs older than {$days} days.");

        return self::SUCCESS;
    }
}
