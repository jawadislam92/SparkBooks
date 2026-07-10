<?php

namespace Tests\Unit;

use App\Services\DocumentLineCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * DocumentLineCalculator is pure (no DB, no float — mirrors InvoiceCalculator's
 * own doc comment), so this extends PHPUnit's own TestCase (not
 * Tests\TestCase / RefreshDatabase). Lines here are AMOUNT-based (net minor
 * unit + optional tax rate) — no quantity x unit_price like invoices (spec
 * §7.8): each line is already the net amount the user typed.
 */
class DocumentLineCalculatorTest extends TestCase
{
    public function test_empty_lines_yields_zero_totals(): void
    {
        $result = DocumentLineCalculator::compute([]);

        $this->assertSame([], $result['lines']);
        $this->assertSame(0, $result['subtotal']);
        $this->assertSame(0, $result['tax_total']);
        $this->assertSame(0, $result['total']);
    }

    public function test_single_untaxed_line(): void
    {
        $result = DocumentLineCalculator::compute([
            ['amount' => 150000, 'tax_rate' => null],
        ]);

        $this->assertSame([
            ['amount' => 150000, 'line_tax' => 0],
        ], $result['lines']);
        $this->assertSame(150000, $result['subtotal']);
        $this->assertSame(0, $result['tax_total']);
        $this->assertSame(150000, $result['total']);
    }

    /** The brief's own worked example: 10,000 net @ 18% -> 1,800 tax. */
    public function test_single_taxed_line_matches_the_briefs_worked_example(): void
    {
        $result = DocumentLineCalculator::compute([
            ['amount' => 10000, 'tax_rate' => '18'],
        ]);

        $this->assertSame([
            ['amount' => 10000, 'line_tax' => 1800],
        ], $result['lines']);
        $this->assertSame(10000, $result['subtotal']);
        $this->assertSame(1800, $result['tax_total']);
        $this->assertSame(11800, $result['total']);
    }

    /** Odd paisa: 33.33 (3333 minor) at 18% -> 599.94 rounds half-up to 600. */
    public function test_odd_paisa_line_at_eighteen_percent_rounds_half_up(): void
    {
        $result = DocumentLineCalculator::compute([
            ['amount' => 3333, 'tax_rate' => '18'],
        ]);

        $this->assertSame(3333, $result['lines'][0]['amount']);
        $this->assertSame(600, $result['lines'][0]['line_tax']);
        $this->assertSame(3933, $result['total']);
    }

    /**
     * Multi-line, mixed taxed/untaxed — each line rounds independently and
     * document totals are plain sums of the per-line results, never a single
     * rate applied to a pre-summed subtotal.
     */
    public function test_multi_line_mixed_taxed_and_untaxed_with_odd_paisa(): void
    {
        $result = DocumentLineCalculator::compute([
            // 9999 x 18% = 1799.82 -> half-up 1800.
            ['amount' => 9999, 'tax_rate' => '18'],
            // Untaxed.
            ['amount' => 50000, 'tax_rate' => null],
            // 5000 x 5% = 250, exact.
            ['amount' => 5000, 'tax_rate' => '5'],
        ]);

        $this->assertSame([
            ['amount' => 9999, 'line_tax' => 1800],
            ['amount' => 50000, 'line_tax' => 0],
            ['amount' => 5000, 'line_tax' => 250],
        ], $result['lines']);

        $this->assertSame(64999, $result['subtotal']);
        $this->assertSame(2050, $result['tax_total']);
        $this->assertSame(67049, $result['total']);
    }

    public function test_empty_string_tax_rate_is_not_treated_as_untaxed_and_is_rejected(): void
    {
        // An empty-string rate is a malformed input, not "no tax" — that's
        // spelled `null`. Mirrors InvoiceCalculatorTest's identical case.
        $this->expectException(InvalidArgumentException::class);

        DocumentLineCalculator::compute([
            ['amount' => 10000, 'tax_rate' => ''],
        ]);
    }

    public function test_negative_amount_is_rejected_loudly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DocumentLineCalculator::compute([
            ['amount' => -100, 'tax_rate' => null],
        ]);
    }
}
