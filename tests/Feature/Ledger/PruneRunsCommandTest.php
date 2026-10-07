<?php

declare(strict_types=1);

namespace Unified\AiCore\Tests\Feature\Ledger;

use Unified\AiCore\Ledger\Run;
use Unified\AiCore\Ledger\RunStep;
use Unified\AiCore\Phi\PurgeHooks;
use Unified\AiCore\Phi\PurgeReason;
use Unified\AiCore\Proposal\Proposal;
use Unified\AiCore\Tests\TestCase;

class PruneRunsCommandTest extends TestCase
{
    public function test_deletes_runs_and_steps_older_than_the_window(): void
    {
        $old = Run::query()->create(['domain' => 'cad.dispatch', 'status' => 'completed']);
        $old->forceFill(['created_at' => now()->subDays(40)])->save();
        RunStep::query()->create(['run_id' => $old->id, 'seq' => 1, 'role' => 'user']);
        $recent = Run::query()->create(['domain' => 'cad.dispatch', 'status' => 'completed']);
        RunStep::query()->create(['run_id' => $recent->id, 'seq' => 1, 'role' => 'user']);

        $this->artisan('ai:prune-runs', ['--days' => 30])
            ->expectsOutputToContain('Deleted 1 AI runs and 0 finished proposals older than 30 days.')
            ->assertSuccessful();

        $this->assertSame([$recent->id], Run::query()->pluck('id')->all());
        $this->assertSame(1, RunStep::query()->count());
    }

    public function test_defaults_to_configured_retention_and_rejects_zero(): void
    {
        config()->set('ai.ledger.retention_days', 10);
        $run = Run::query()->create(['domain' => 'x', 'status' => 'completed']);
        $run->forceFill(['created_at' => now()->subDays(11)])->save();

        $this->artisan('ai:prune-runs')->assertSuccessful();
        $this->assertSame(0, Run::query()->count());

        $this->artisan('ai:prune-runs', ['--days' => 0])->assertFailed();
    }

    public function test_prunes_finished_proposals_after_running_their_purge_hooks(): void
    {
        $purged = [];
        app(PurgeHooks::class)->register('cad.dispatch', function (PurgeReason $reason, array $context) use (&$purged): void {
            $purged[$context['proposal_id']] = $reason;
        });

        $make = function (string $status, int $daysAgo): Proposal {
            $proposal = Proposal::query()->create(['domain' => 'cad.dispatch', 'status' => $status, 'plan' => ['summary' => 's', 'changes' => []], 'plan_hash' => 'h']);
            Proposal::query()->whereKey($proposal->id)->update(['updated_at' => now()->subDays($daysAgo)]);

            return $proposal;
        };

        $executed = $make('executed', 40);
        $verified = $make('verified', 40);
        $rejected = $make('rejected', 40);
        $failed = $make('failed', 40);
        $openOld = $make('approved', 40);
        $executingOld = $make('executing', 40);
        $recent = $make('rejected', 5);

        $this->artisan('ai:prune-runs', ['--days' => 30])
            ->expectsOutputToContain('Deleted 0 AI runs and 4 finished proposals older than 30 days.')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing([$openOld->id, $executingOld->id, $recent->id], Proposal::query()->pluck('id')->all());
        $this->assertSame([
            $executed->id => PurgeReason::Executed,
            $verified->id => PurgeReason::Executed,
            $rejected->id => PurgeReason::Abandoned,
            $failed->id => PurgeReason::Abandoned,
        ], $purged);
    }
}
