<?php

namespace Tests\Unit;

use App\Services\InvoiceCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * InvoiceCalculator is pure (no DB, no float — see its own class doc), so
 * this extends PHPUnit's own TestCase (not Tests\TestCase / RefreshDatabase),
 * mirroring MoneyTest.
 */
class InvoiceCalculatorTest extends TestCase
{
    public function test_empty_lines_yields_zero_totals(): void
    {
        $result = InvoiceCalculator::compute([]);

        $this->assertSame([], $result['lines']);
        $this->assertSame(0, $result['subtotal']);
        $this->assertSame(0, $result['tax_total']);
        $this->assertSame(0, $result['total']);
    }

    public function test_single_untaxed_line(): void
    {
        $result = InvoiceCalculator::compute([
            ['quantity' => '1', 'unit_price' => 150000, 'tax_rate' => null],
        ]);

        $this->assertSame([
            ['line_subtotal' => 150000, 'line_tax' => 0],
        ], $result['lines']);
        $this->assertSame(150000, $result['subtotal']);
        $this->assertSame(0, $result['tax_total']);
        $this->assertSame(150000, $result['total']);
    }

    public function test_single_taxed_line(): void
    {
        $result = InvoiceCalculator::compute([
            ['quantity' => '1', 'unit_price' => 10000, 'tax_rate' => '18'],
        ]);

        $this->assertSame([
            ['line_subtotal' => 10000, 'line_tax' => 1800],
        ], $result['lines']);
        $this->assertSame(10000, $result['subtotal']);
        $this->assertSame(1800, $result['tax_total']);
        $this->assertSame(11800, $result['total']);
    }

    /** A line quantity that isn't 1 — subtotal comes from quantity x unit_price, not unit_price alone. */
    public function test_quantity_multiplies_unit_price_before_tax(): void
    {
        $result = InvoiceCalculator::compute([
            ['quantity' => '2.5', 'unit_price' => 10000, 'tax_rate' => '18'],
        ]);

        // 2.5 x 10000 = 25000 subtotal; 25000 x 18% = 4500 tax.
        $this->assertSame(25000, $result['lines'][0]['line_subtotal']);
        $this->assertSame(4500, $result['lines'][0]['line_tax']);
        $this->assertSame(29500, $result['total']);
    }

    /** The brief's own odd-paisa worked example: 33.33 (3333 minor) at 18% -> 599.94 rounds to 600. */
    public function test_odd_paisa_line_at_eighteen_percent_rounds_half_up(): void
    {
        $result = InvoiceCalculator::compute([
            ['quantity' => '1', 'unit_price' => 3333, 'tax_rate' => '18'],
        ]);

        $this->assertSame(3333, $result['lines'][0]['line_subtotal']);
        $this->assertSame(600, $result['lines'][0]['line_tax']);
        $this->assertSame(3933, $result['total']);
    }

    /**
     * Multi-line, mixed taxed/untaxed, mixed quantities — each line rounds
     * independently and the document totals are plain sums of the per-line
     * results (never a single rate applied to a pre-summed subtotal).
     */
    public function test_multi_line_mixed_taxed_and_untaxed_with_odd_paisa(): void
    {
        $result = InvoiceCalculator::compute([
            // 3 x 3333 = 9999 subtotal; 18% of 9999 = 1799.82 -> 1800.
            ['quantity' => '3', 'unit_price' => 3333, 'tax_rate' => '18'],
            // 1 x 50000 = 50000 subtotal; untaxed.
            ['quantity' => '1', 'unit_price' => 50000, 'tax_rate' => null],
            // 1.5 x 3333 = 4999.5 -> half-up 5000 subtotal; 5% of 5000 = 250.
            ['quantity' => '1.5', 'unit_price' => 3333, 'tax_rate' => '5'],
        ]);

        $this->assertSame([
            ['line_subtotal' => 9999, 'line_tax' => 1800],
            ['line_subtotal' => 50000, 'line_tax' => 0],
            ['line_subtotal' => 5000, 'line_tax' => 250],
        ], $result['lines']);

        $this->assertSame(64999, $result['subtotal']);
        $this->assertSame(2050, $result['tax_total']);
        $this->assertSame(67049, $result['total']);
    }

    public function test_empty_string_tax_rate_is_not_treated_as_untaxed_and_is_rejected(): void
    {
        // An empty-string rate is a malformed input, not "no tax" — that's
        // spelled `null`. Money::taxMinorUnits' own hardening rejects it
        // (empty string fails the non-negative-decimal guard), so this
        // documents that InvoiceCalculator does not paper over the mistake.
        $this->expectException(InvalidArgumentException::class);

        InvoiceCalculator::compute([
            ['quantity' => '1', 'unit_price' => 10000, 'tax_rate' => ''],
        ]);
    }

    public function test_negative_unit_price_is_rejected_loudly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        InvoiceCalculator::compute([
            ['quantity' => '1', 'unit_price' => -100, 'tax_rate' => null],
        ]);
    }
}
