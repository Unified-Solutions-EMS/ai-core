<?php

declare(strict_types=1);

namespace Unified\AiCore\Tests\Feature\Ledger;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Unified\AiCore\Ledger\RunSummary;
use Unified\AiCore\Tests\TestCase;

class RunSummaryTest extends TestCase
{
    public function test_renders_label_and_integer_placeholders(): void
    {
        $this->assertSame(
            'CAD dispatch: 3 trips, 3 assigned, 0 unassignable',
            RunSummary::render('CAD dispatch', '{trips} trips, {assigned} assigned, {unassignable} unassignable', ['trips' => 3, 'assigned' => 3, 'unassignable' => 0]),
        );
    }

    public function test_index_line_appends_the_outcome(): void
    {
        $this->assertSame('CAD dispatch: 3 trips, approved', RunSummary::forIndex('CAD dispatch: 3 trips', 'cad.dispatch', 'approved'));
        $this->assertSame('cad.dispatch, failed', RunSummary::forIndex(null, 'cad.dispatch', 'failed'));
        $this->assertSame('cad.dispatch, completed', RunSummary::forIndex("Patient O'Brien \"chest pain\"", 'cad.dispatch', 'completed'), 'free text written around the recorder is dropped');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeTemplates(): array
    {
        return [
            'quotes' => ['Patient "{name}"'],
            'apostrophe' => ["O'Brien {n}"],
            'at sign' => ['{n} sent to jo@example.com'],
            'stray brace' => ['{n} trips }'],
            'uppercase placeholder' => ['{Trips} trips'],
            'too long' => [str_repeat('a', 161)],
            'newline' => ["{n}\ntrips"],
        ];
    }

    #[DataProvider('unsafeTemplates')]
    public function test_refuses_unsafe_templates(string $template): void
    {
        $this->expectException(InvalidArgumentException::class);
        RunSummary::assertTemplate($template);
    }

    public function test_refuses_unsafe_labels(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RunSummary::assertLabel('Dispatch for "Jane Doe"');
    }

    public function test_refuses_missing_and_non_integer_counts(): void
    {
        try {
            RunSummary::render('CAD', '{trips} trips', []);
            $this->fail('missing count accepted');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        RunSummary::render('CAD', '{trips} trips', ['trips' => 'John Doe']);
    }
}
