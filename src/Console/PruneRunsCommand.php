<?php

declare(strict_types=1);

namespace Unified\AiCore\Console;

use Illuminate\Console\Command;
use Unified\AiCore\Ledger\Run;
use Unified\AiCore\Ledger\RunStep;
use Unified\AiCore\Phi\PurgeHooks;
use Unified\AiCore\Phi\PurgeReason;
use Unified\AiCore\Proposal\Proposal;
use Unified\AiCore\Proposal\ProposalStatus;

/**
 * Deletes ledger runs (and their steps) and finished proposals older than
 * the retention window. Run inputs and plan before/after values can hold
 * PHI, so neither table should grow forever. Each proposal's purge hooks
 * run before its row goes, so uploads and transcripts it pointed at are
 * removed too. Proposals still open (drafted, refined, approved,
 * executing) are never pruned.
 */
class PruneRunsCommand extends Command
{
    protected $signature = 'ai:prune-runs {--days= : Delete rows older than this many days (default: ai.ledger.retention_days)}';

    protected $description = 'Delete AI runs and finished proposals older than the retention window';

    public function handle(PurgeHooks $purgeHooks): int
    {
        $days = (int) ($this->option('days') ?? config('ai.ledger.retention_days', 365));

        if ($days < 1) {
            $this->error('--days must be at least 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $proposals = $this->pruneProposals($purgeHooks, $cutoff);
        $runs = $this->pruneRuns($cutoff);

        $this->info("Deleted {$runs} AI runs and {$proposals} finished proposals older than {$days} days.");

        return self::SUCCESS;
    }

    private function pruneProposals(PurgeHooks $purgeHooks, \DateTimeInterface $cutoff): int
    {
        /** @var class-string<Proposal> $model */
        $model = config('ai.models.proposal', Proposal::class);
        $deleted = 0;

        $finished = [ProposalStatus::Executed, ProposalStatus::Verified, ProposalStatus::Rejected, ProposalStatus::Failed];

        $model::query()
            ->whereIn('status', array_map(fn (ProposalStatus $s): string => $s->value, $finished))
            ->where('updated_at', '<', $cutoff)
            ->chunkById(200, function ($proposals) use ($purgeHooks, $model, &$deleted): void {
                foreach ($proposals as $proposal) {
                    $reason = in_array($proposal->status, [ProposalStatus::Executed, ProposalStatus::Verified], true)
                        ? PurgeReason::Executed
                        : PurgeReason::Abandoned;

                    $purgeHooks->run($proposal->domain, $reason, [
                        'proposal_id' => $proposal->id,
                        'proposal_uuid' => $proposal->uuid,
                        'company_id' => $proposal->company_id,
                        'run_id' => $proposal->run_id,
                    ]);

                    $deleted += $model::query()->whereKey($proposal->id)->where('status', $proposal->status->value)->delete();
                }
            });

        return $deleted;
    }

    private function pruneRuns(\DateTimeInterface $cutoff): int
    {
        /** @var class-string<Run> $runModel */
        $runModel = config('ai.models.run', Run::class);
        /** @var class-string<RunStep> $stepModel */
        $stepModel = config('ai.models.run_step', RunStep::class);
        $deleted = 0;

        $runModel::query()
            ->where('created_at', '<', $cutoff)
            ->select('id')
            ->chunkById(500, function ($runs) use ($stepModel, $runModel, &$deleted): void {
                $ids = $runs->pluck('id')->all();
                $stepModel::query()->whereIn('run_id', $ids)->delete();
                $deleted += $runModel::query()->whereIn('id', $ids)->delete();
            });

        return $deleted;
    }
}
