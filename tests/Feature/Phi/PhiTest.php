<?php

declare(strict_types=1);

namespace Unified\AiCore\Tests\Feature\Phi;

use RuntimeException;
use Unified\AiCore\Phi\ModelDisclosure;
use Unified\AiCore\Phi\PhiPosture;
use Unified\AiCore\Phi\PurgeHook;
use Unified\AiCore\Phi\PurgeHooks;
use Unified\AiCore\Phi\PurgeReason;
use Unified\AiCore\Tests\TestCase;

class PhiTest extends TestCase
{
    protected function tearDown(): void
    {
        ModelDisclosure::flush();
        parent::tearDown();
    }

    public function test_posture_defaults_to_redacted_for_anything_unknown(): void
    {
        $this->assertSame(PhiPosture::Redacted, PhiPosture::fromConfig(null));
        $this->assertSame(PhiPosture::Redacted, PhiPosture::fromConfig('everything'));
        $this->assertSame(PhiPosture::Rows, PhiPosture::fromConfig('rows'));
        $this->assertTrue(PhiPosture::Redacted->allowsRows());
        $this->assertFalse(PhiPosture::Redacted->allowsIdentifiedRows());
        $this->assertFalse(PhiPosture::AggregatesOnly->allowsRows());
        $this->assertTrue(PhiPosture::Rows->allowsIdentifiedRows());
    }

    public function test_disclosure_reads_config_and_redacts_identifiers(): void
    {
        ModelDisclosure::extend(['patient_middle_name']);

        $this->assertSame(PhiPosture::Redacted, ModelDisclosure::posture());
        $this->assertTrue(ModelDisclosure::isDirectIdentifier('patient_ssn'));
        $this->assertTrue(ModelDisclosure::isDirectIdentifier('patient_middle_name'));
        $this->assertSame(['patient_dob'], ModelDisclosure::directIdentifiersIn(['age', 'patient_dob']));

        $rows = [['patient_last_name' => 'Doe', 'age' => 40]];
        $this->assertSame([['age' => 40]], ModelDisclosure::redactRows($rows));
        $this->assertSame($rows, ModelDisclosure::redactRows($rows, PhiPosture::Rows));

        config()->set('ai.phi.posture', 'aggregates_only');
        $this->assertFalse(ModelDisclosure::allowsRows());
        $this->assertStringContainsString('never individual records', ModelDisclosure::notice('Bridge'));
    }

    public function test_purge_hooks_run_per_domain_and_survive_a_failing_hook(): void
    {
        $hooks = app(PurgeHooks::class);
        $calls = [];

        $hooks->register('cad.dispatch', function () {
            throw new RuntimeException('disk gone');
        });
        $hooks->register('cad.dispatch', function (PurgeReason $reason, array $context) use (&$calls): void {
            $calls[] = [$reason, $context['proposal_id']];
        });
        $hooks->register('cad.dispatch', new class implements PurgeHook
        {
            public static int $ran = 0;

            public function purge(PurgeReason $reason, array $context): void
            {
                self::$ran++;
            }
        });
        $hooks->register('crew.scheduling', function () use (&$calls): void {
            $calls[] = 'wrong domain';
        });

        $ran = $hooks->run('cad.dispatch', PurgeReason::Abandoned, ['proposal_id' => 9]);

        $this->assertSame(2, $ran);
        $this->assertSame([[PurgeReason::Abandoned, 9]], $calls);
        $this->assertTrue($hooks->has('cad.dispatch'));
        $this->assertFalse($hooks->has('nothing'));
    }
}
