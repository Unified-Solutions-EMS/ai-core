<?php

declare(strict_types=1);

namespace Unified\AiCore\Console;

use Illuminate\Console\Command;
use Unified\AiCore\Proposal\Proposal;
use Unified\AiCore\Proposal\ProposalService;
use Unified\AiCore\Proposal\ProposalStatus;

/**
 * Moves proposals stuck in 'executing' (the worker died mid-run: timeout,
 * out of memory, deploy) to 'failed' with a reason, so a person can see
 * which changes were applied and retry. Executors are idempotent per
 * change, so a retry is safe. Schedule it every few minutes.
 */
class RecoverStuckProposalsCommand extends Command
{
    protected $signature = 'ai:recover-stuck-proposals {--minutes= : Treat executions older than this as stuck (default: ai.proposals.stuck_after_minutes)}';

    protected $description = 'Mark AI proposals stuck in executing as failed';

    public function handle(ProposalService $service): int
    {
        $minutes = (int) ($this->option('minutes') ?? config('ai.proposals.stuck_after_minutes', 30));

        if ($minutes < 1) {
            $this->error('--minutes must be at least 1.');

            return self::FAILURE;
        }

        /** @var class-string<Proposal> $model */
        $model = config('ai.models.proposal', Proposal::class);
        $recovered = 0;

        $model::query()
            ->where('status', ProposalStatus::Executing->value)
            ->where('executing_at', '<', now()->subMinutes($minutes))
            ->chunkById(200, function ($proposals) use ($service, $minutes, &$recovered): void {
                foreach ($proposals as $proposal) {
                    if ($proposal->isStale($minutes) && $service->markStuck($proposal, "Execution did not finish within {$minutes} minutes and was stopped. Some changes may already be applied; check the results before retrying.")) {
                        $recovered++;
                    }
                }
            });

        $this->info("Marked {$recovered} stuck proposals as failed.");

        return self::SUCCESS;
    }
}
