<?php

declare(strict_types=1);

namespace Unified\AiCore\Tests\Feature\Proposal;

use Illuminate\Support\Facades\Bus;
use Unified\AiCore\Ledger\Jobs\RegisterRunWithSso;
use Unified\AiCore\Phi\PurgeHooks;
use Unified\AiCore\Phi\PurgeReason;
use Unified\AiCore\Proposal\Plan;
use Unified\AiCore\Proposal\PlannedChange;
use Unified\AiCore\Proposal\Proposal;
use Unified\AiCore\Proposal\ProposalService;
use Unified\AiCore\Proposal\ProposalStateChanged;
use Unified\AiCore\Proposal\ProposalStateException;
use Unified\AiCore\Proposal\ProposalStatus;
use Unified\AiCore\Tests\TestCase;

/**
 * Two requests acting on one proposal, simulated by loading two copies of
 * the row and letting one write before the other.
 */
class ProposalConcurrencyTest extends TestCase
{
    /** @var list<PurgeReason> */
    private array $purged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([RegisterRunWithSso::class]);

        app(PurgeHooks::class)->register('cad.dispatch', function (PurgeReason $reason): void {
            $this->purged[] = $reason;
        });
    }

    private function service(): ProposalService
    {
        return app(ProposalService::class);
    }

    private function plan(string $unit): Plan
    {
        return new Plan("Assign {$unit}", [new PlannedChange('cad', 'trip:1', null, ['unit' => $unit], 'closest', PlannedChange::RISK_LOW)]);
    }

    private function copy(Proposal $proposal): Proposal
    {
        return Proposal::query()->findOrFail($proposal->id);
    }

    private function approved(string $unit = 'M4'): Proposal
    {
        $proposal = $this->service()->draft('cad.dispatch', $this->plan($unit), 7, 3);

        return $this->service()->approve($proposal, 4, 'Approve and apply');
    }

    public function test_execute_refuses_a_plan_reapproved_after_it_was_loaded(): void
    {
        $requestA = $this->approved('M4');
        $requestB = $this->copy($requestA);

        $this->service()->refine($requestB, $this->plan('M9'));
        $this->service()->approve($requestB, 5, 'Approve and apply');

        $ran = [];

        try {
            $this->service()->execute($requestA, function (PlannedChange $change) use (&$ran): void {
                $ran[] = $change->after['unit'];
            });
            $this->fail('A ran a plan nobody approved any more');
        } catch (ProposalStateException $e) {
            $this->assertStringContainsString('plan changed', $e->getMessage());
        }

        $this->assertSame([], $ran);
        $fresh = $requestA->fresh();
        $this->assertSame(ProposalStatus::Refined, $fresh->status, 'back for approval');
        $this->assertNull($fresh->approved_plan_hash);
    }

    public function test_execute_claim_requires_the_approved_hash_in_the_same_update(): void
    {
        $proposal = $this->approved();
        Proposal::query()->whereKey($proposal->id)->update(['approved_plan_hash' => str_repeat('0', 64)]);
        $proposal->refresh();

        $this->expectException(ProposalStateException::class);

        try {
            $this->service()->execute($proposal, fn () => 'ran');
        } finally {
            $this->assertSame(ProposalStatus::Refined, $proposal->fresh()->status);
        }
    }

    public function test_reject_during_execution_is_refused_and_does_not_purge(): void
    {
        $executing = $this->approved();
        $staleCopy = $this->copy($executing);

        $this->service()->execute($executing, function () use ($staleCopy): string {
            try {
                $this->service()->reject($staleCopy, 9, 'Changed my mind');
                $this->fail('Reject overwrote an executing proposal');
            } catch (ProposalStateException $e) {
                $this->assertNotInstanceOf(ProposalStateChanged::class, $e);
                $this->assertStringContainsString('being applied', $e->getMessage());
                $this->assertSame(409, ProposalStateException::HTTP_STATUS);
            }

            $this->assertSame([], $this->purged, 'abandoned cleanup ran while executing');

            return 'ok';
        });

        $this->assertSame(ProposalStatus::Executed, $executing->fresh()->status);
        $this->assertSame([PurgeReason::Executed], $this->purged);
    }

    public function test_reject_from_a_freshly_loaded_executing_row_is_refused(): void
    {
        $proposal = $this->approved();
        Proposal::query()->whereKey($proposal->id)->update(['status' => 'executing', 'executing_at' => now()]);

        $this->expectException(ProposalStateException::class);
        $this->service()->reject($this->copy($proposal), 9, 'no');
    }

    public function test_refine_during_execution_is_refused_so_the_audit_plan_stays_the_one_that_ran(): void
    {
        $executing = $this->approved('M4');
        $staleCopy = $this->copy($executing);

        $this->service()->execute($executing, function () use ($staleCopy): string {
            try {
                $this->service()->refine($staleCopy, $this->plan('M9'));
                $this->fail('Refine overwrote an executing proposal');
            } catch (ProposalStateException) {
            }

            return 'ok';
        });

        $fresh = $executing->fresh();
        $this->assertSame(ProposalStatus::Executed, $fresh->status);
        $this->assertSame('M4', $fresh->planObject()->changes[0]->after['unit']);
    }

    public function test_two_approvals_of_one_draft_the_second_loses(): void
    {
        $draft = $this->service()->draft('cad.dispatch', $this->plan('M4'), 7, 3);
        $copy = $this->copy($draft);

        $this->service()->approve($draft, 4, 'first');

        $this->expectException(ProposalStateChanged::class);
        $this->service()->approve($copy, 5, 'second');
    }

    public function test_approve_loses_to_a_refine_that_landed_first(): void
    {
        $draft = $this->service()->draft('cad.dispatch', $this->plan('M4'), 7, 3);
        $copy = $this->copy($draft);

        $this->service()->refine($draft, $this->plan('M9'));

        try {
            $this->service()->approve($copy, 5, 'Approve and apply');
            $this->fail('Approval of the old plan went through');
        } catch (ProposalStateChanged) {
        }

        $fresh = $draft->fresh();
        $this->assertSame(ProposalStatus::Refined, $fresh->status);
        $this->assertNull($fresh->approved_by);
    }

    public function test_reject_loses_to_an_approve_and_runs_no_purge(): void
    {
        $draft = $this->service()->draft('cad.dispatch', $this->plan('M4'), 7, 3);
        $copy = $this->copy($draft);

        $this->service()->approve($draft, 4, 'go');

        try {
            $this->service()->reject($copy, 5, 'no');
            $this->fail('Reject overwrote a newer approval');
        } catch (ProposalStateChanged) {
        }

        $this->assertSame([], $this->purged);
        $this->assertSame(ProposalStatus::Approved, $draft->fresh()->status);
    }

    public function test_verify_twice_the_second_loses(): void
    {
        $proposal = $this->approved();
        $this->service()->execute($proposal, fn () => 'ok');
        $copy = $this->copy($proposal);

        $this->service()->verify($proposal, 4);

        $this->expectException(ProposalStateChanged::class);
        $this->service()->verify($copy, 5);
    }

    public function test_execution_records_who_claimed_it_and_when(): void
    {
        $proposal = $this->approved();

        $this->service()->execute($proposal, fn () => 'ok', executedBy: 4);

        $fresh = $proposal->fresh();
        $this->assertSame(4, $fresh->executing_by);
        $this->assertNotNull($fresh->executing_at);
    }

    public function test_stuck_executions_are_recovered_as_failed(): void
    {
        $stuck = $this->approved();
        Proposal::query()->whereKey($stuck->id)->update(['status' => 'executing', 'executing_at' => now()->subHour()]);
        $recent = $this->approved();
        Proposal::query()->whereKey($recent->id)->update(['status' => 'executing', 'executing_at' => now()->subMinutes(5)]);

        $this->assertTrue($stuck->fresh()->isStale(30));
        $this->assertFalse($recent->fresh()->isStale(30));

        $this->artisan('ai:recover-stuck-proposals', ['--minutes' => 30])
            ->expectsOutputToContain('Marked 1 stuck proposals as failed.')
            ->assertSuccessful();

        $this->assertSame(ProposalStatus::Failed, $stuck->fresh()->status);
        $this->assertStringContainsString('did not finish within 30 minutes', (string) $stuck->fresh()->failed_reason);
        $this->assertSame(ProposalStatus::Executing, $recent->fresh()->status);

        $this->artisan('ai:recover-stuck-proposals', ['--minutes' => 0])->assertFailed();
    }

    public function test_a_worker_finishing_after_recovery_keeps_failed_but_saves_its_results(): void
    {
        $proposal = $this->approved();

        try {
            $this->service()->execute($proposal, function () use ($proposal): string {
                Proposal::query()->whereKey($proposal->id)->update(['executing_at' => now()->subHour()]);
                $this->artisan('ai:recover-stuck-proposals', ['--minutes' => 30])->assertSuccessful();

                return 'applied late';
            });
            $this->fail('Expected the late finish to be reported');
        } catch (ProposalStateChanged) {
        }

        $fresh = $proposal->fresh();
        $this->assertSame(ProposalStatus::Failed, $fresh->status);
        $this->assertSame('applied late', $fresh->results[0]['result']);
        $this->assertSame([], $this->purged);
    }
}
