<?php

declare(strict_types=1);

namespace Unified\AiCore\Tests\Feature\Ledger;

use Unified\AiCore\Ledger\Run;
use Unified\AiCore\Ledger\RunStep;
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
            ->expectsOutputToContain('Deleted 1 AI runs older than 30 days.')
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
}
