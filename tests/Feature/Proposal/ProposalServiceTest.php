<?php

declare(strict_types=1);

namespace Unified\AiCore\Tests\Feature\Proposal;

use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Unified\AiCore\Ledger\Jobs\RegisterRunWithSso;
use Unified\AiCore\Ledger\Run;
use Unified\AiCore\Phi\PurgeHooks;
use Unified\AiCore\Phi\PurgeReason;
use Unified\AiCore\Proposal\Plan;
use Unified\AiCore\Proposal\PlannedChange;
use Unified\AiCore\Proposal\Proposal;
use Unified\AiCore\Proposal\ProposalService;
use Unified\AiCore\Proposal\ProposalStateException;
use Unified\AiCore\Proposal\ProposalStatus;
use Unified\AiCore\Tests\TestCase;

class ProposalServiceTest extends TestCase
{
    /** @var list<array{0: PurgeReason, 1: array<string, mixed>}> */
    private array $purged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([RegisterRunWithSso::class]);

        app(PurgeHooks::class)->register('cad.dispatch', function (PurgeReason $reason, array $context): void {
            $this->purged[] = [$reason, $context];
        });
    }

    private function service(): ProposalService
    {
        return app(ProposalService::class);
    }

    private function plan(string $unit = 'M4', int $changes = 1): Plan
    {
        $list = [];
        for ($i = 0; $i < $changes; $i++) {
            $list[] = new PlannedChange('cad', "trip:{$i}", ['unit' => null], ['unit' => $unit], 'closest', PlannedChange::RISK_LOW);
        }

        return new Plan("Assign {$unit}", $list);
    }

    public function test_draft_stores_the_plan_and_its_hash(): void
    {
        $proposal = $this->service()->draft('cad.dispatch', $this->plan(), 7, 3);

        $this->assertSame(ProposalStatus::Drafted, $proposal->status);
        $this->assertSame($this->plan()->hash(), $proposal->plan_hash);
        $this->assertSame('testapp', $proposal->app_slug);
        $this->assertNotEmpty($proposal->uuid);
        $this->assertEquals($this->plan(), $proposal->fresh()->planObject());
    }

    public function test_plan_hash_ignores_key_order_but_not_content(): void
    {
        $a = Plan::fromArray(['summary' => 's', 'changes' => [['target' => 'cad', 'entity' => 'e', 'after' => ['a' => 1, 'b' => 2]]]]);
        $b = Plan::fromArray(['changes' => [['after' => ['b' => 2, 'a' => 1], 'entity' => 'e', 'target' => 'cad']], 'summary' => 's']);
        $c = Plan::fromArray(['summary' => 's', 'changes' => [['target' => 'cad', 'entity' => 'e', 'after' => ['a' => 1, 'b' => 3]]]]);

        $this->assertSame($a->hash(), $b->hash());
        $this->assertNotSame($a->hash(), $c->hash());
    }

    public function test_refine_replaces_the_plan_and_clears_an_approval(): void
    {
        $proposal = $this->service()->draft('cad.dispatch', $this->plan(), 7, 3);
        $this->service()->approve($proposal, 3, 'Approve and apply');

        $this->service()->refine($proposal, $this->plan('M9'));

        $this->assertSame(ProposalStatus::Refined, $proposal->status);
        $this->assertSame($this->plan('M9')->hash(), $proposal->plan_hash);
        $this->assertNull($proposal->approved_by);
        $this->assertNull($proposal->approved_plan_hash);
        $this->assertNull($proposal->approval_instruction);
    }

    public function test_approval_of_a_stale_plan_hash_is_refused(): void
    {
        $proposal = $this->service()->draft('cad.dispatch', $this->plan(), 7, 3);
        $shownHash = $proposal->plan_hash;
        $this->service()->refine($proposal, $this->plan('M9'));

        $this->expectException(ProposalStateException::class);
        $this->service()->approve($proposal, 3, 'Approve and apply', $shownHash);
    }

    public function test_approve_records_who_when_what_and_the_hash(): void
    {
        $proposal = $this->service()->draft('cad.dispatch', $this->plan(), 7, 3);

        $this->service()->approve($proposal, 4, 'Approve and apply', $proposal->plan_hash);

        $fresh = $proposal->fresh();
        $this->assertSame(ProposalStatus::Approved, $fresh->status);
        $this->assertSame(4, $fresh->approved_by);
        $this->assertNotNull($fresh->approved_at);
        $this->assertSame('Approve and apply', $fresh->approval_instruction);
        $this->assertSame($fresh->plan_hash, $fresh->approved_plan_hash);
    }

    public function test_execute_requires_approval(): void
    {
        $proposal = $this->service()->draft('cad.dispatch', $this->plan(), 7, 3);

        $this->expectException(ProposalStateException::class);
        $this->service()->execute($proposal, fn () => true);
    }

    public function test_execute_runs_every_change_records_results_and_purges(): void
    {
        $proposal = $this->service()->draft('cad.dispatch', $this->plan(changes: 2), 7, 3);
        $this->service()->approve($proposal, 4, 'go');
        $seen = [];

        $this->service()->execute($proposal, function (PlannedChange $change, int $index) use (&$seen): array {
            $seen[] = $change->entity;

            return ['row' => $index + 100];
        });

        $this->assertSame(['trip:0', 'trip:1'], $seen);
        $fresh = $proposal->fresh();
        $this->assertSame(ProposalStatus::Executed, $fresh->status);
        $this->assertNotNull($fresh->executed_at);
        $this->assertSame([['index' => 0, 'ok' => true, 'result' => ['row' => 100]], ['index' => 1, 'ok' => true, 'result' => ['row' => 101]]], $fresh->results);
        $this->assertSame(PurgeReason::Executed, $this->purged[0][0]);
        $this->assertSame($proposal->id, $this->purged[0][1]['proposal_id']);
    }

    public function test_partial_failure_is_recorded_and_ends_failed(): void
    {
        $proposal = $this->service()->draft('cad.dispatch', $this->plan(changes: 3), 7, 3);
        $this->service()->approve($proposal, 4, 'go');

        $this->service()->execute($proposal, function (PlannedChange $change, int $index): string {
            if ($index === 1) {
                throw new RuntimeException('unit went out of service');
            }

            return 'ok';
        });

        $fresh = $proposal->fresh();
        $this->assertSame(ProposalStatus::Failed, $fresh->status);
        $this->assertSame([true, false, true], array_column($fresh->results, 'ok'));
        $this->assertSame('unit went out of service', $fresh->results[1]['error']);
        $this->assertSame([], $this->purged);
    }

    public function test_stop_on_failure_skips_the_rest(): void
    {
        $proposal = $this->service()->draft('cad.dispatch', $this->plan(changes: 3), 7, 3);
        $this->service()->approve($proposal, 4, 'go');

        $this->service()->execute($proposal, fn (PlannedChange $c, int $i) => $i === 0 ? throw new RuntimeException('x') : 'ok', stopOnFailure: true);

        $this->assertSame([false, false, false], array_column($proposal->fresh()->results, 'ok'));
        $this->assertTrue($proposal->fresh()->results[2]['skipped']);
    }

    public function test_execution_is_claimed_once_across_overlapping_requests(): void
    {
        $proposal = $this->service()->draft('cad.dispatch', $this->plan(), 7, 3);
        $this->service()->approve($proposal, 4, 'go');
        $otherRequestCopy = Proposal::query()->find($proposal->id);

        $this->service()->execute($proposal, fn () => 'ok');

        $runs = 0;
        try {
            $this->service()->execute($otherRequestCopy, function () use (&$runs) {
                $runs++;
            });
            $this->fail('Expected the second execution to be refused');
        } catch (ProposalStateException) {
            $this->assertSame(0, $runs);
        }
    }

    public function test_a_plan_changed_behind_the_approval_aborts_and_needs_reapproval(): void
    {
        $proposal = $this->service()->draft('cad.dispatch', $this->plan(), 7, 3);
        $this->service()->approve($proposal, 4, 'go');
        $proposal->forceFill(['plan' => $this->plan('EVIL')->toArray()])->save();
        $ran = false;

        try {
            $this->service()->execute($proposal, function () use (&$ran) {
                $ran = true;
            });
            $this->fail('Expected planChanged');
        } catch (ProposalStateException) {
        }

        $this->assertFalse($ran);
        $this->assertSame(ProposalStatus::Refined, $proposal->fresh()->status);
        $this->assertNull($proposal->fresh()->approved_by);
    }

    public function test_verify_only_after_execution(): void
    {
        $proposal = $this->service()->draft('cad.dispatch', $this->plan(), 7, 3);

        try {
            $this->service()->verify($proposal, 4);
            $this->fail('Expected refusal');
        } catch (ProposalStateException) {
        }

        $this->service()->approve($proposal, 4, 'go');
        $this->service()->execute($proposal, fn () => 'ok');
        $this->service()->verify($proposal, 5);

        $this->assertSame(ProposalStatus::Verified, $proposal->fresh()->status);
        $this->assertSame(5, $proposal->fresh()->verified_by);
    }

    public function test_reject_records_reason_and_purges_as_abandoned(): void
    {
        $proposal = $this->service()->draft('cad.dispatch', $this->plan(), 7, 3);

        $this->service()->reject($proposal, 4, 'Wrong unit');

        $this->assertSame(ProposalStatus::Rejected, $proposal->fresh()->status);
        $this->assertSame('Wrong unit', $proposal->fresh()->rejected_reason);
        $this->assertSame(PurgeReason::Abandoned, $this->purged[0][0]);

        $this->expectException(ProposalStateException::class);
        $this->service()->approve($proposal, 4, 'go');
    }

    public function test_linked_run_is_marked_approved_and_executed(): void
    {
        $run = Run::query()->create(['domain' => 'cad.dispatch', 'company_id' => 7, 'status' => 'completed']);
        $proposal = $this->service()->draft('cad.dispatch', $this->plan(), 7, 3, $run);

        $this->assertSame($proposal->id, $run->fresh()->proposal_id);

        $this->service()->approve($proposal, 4, 'go');
        $this->assertSame(4, $run->fresh()->approved_by);

        $this->service()->execute($proposal, fn () => 'ok');
        $fresh = $run->fresh();
        $this->assertNotNull($fresh->executed_at);
        $this->assertSame(['status' => 'executed', 'succeeded' => 1, 'failed' => 0], $fresh->outcome);
        $this->assertSame('executed', $fresh->indexOutcome());

        Bus::assertDispatchedTimes(RegisterRunWithSso::class, 2);
    }

    public function test_status_machine_transitions(): void
    {
        $this->assertTrue(ProposalStatus::Drafted->canTransitionTo(ProposalStatus::Approved));
        $this->assertFalse(ProposalStatus::Drafted->canTransitionTo(ProposalStatus::Executing));
        $this->assertFalse(ProposalStatus::Executed->canTransitionTo(ProposalStatus::Rejected));
        $this->assertTrue(ProposalStatus::Rejected->isTerminal());
        $this->assertTrue(ProposalStatus::Verified->isTerminal());
    }
}
