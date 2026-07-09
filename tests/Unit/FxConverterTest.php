<?php

namespace Tests\Unit;

use App\Support\FxConverter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * FxConverter::toBase is a pure, DB-free helper (like Money), so this extends
 * PHPUnit's own TestCase (not Tests\TestCase / RefreshDatabase) — no app
 * boot, no database, on purpose.
 */
class FxConverterTest extends TestCase
{
    // -----------------------------------------------------------------
    // The spec's own worked numbers (§17.1 P1fx): USD 1,000 + 18% tax @ 280
    // -> Dr AR 330,400 / Cr Income 280,000 / Cr Tax Payable 50,400.
    // -----------------------------------------------------------------

    /** A USD line subtotal of 100000 doc minor (USD 1,000.00) at fx_rate 280 -> base 28,000,000 minor (PKR 280,000.00). */
    public function test_spec_line_subtotal_converts_exactly(): void
    {
        $this->assertSame(28000000, FxConverter::toBase(100000, '280'));
    }

    /** The same invoice's 18% tax, 18000 doc minor (USD 180.00) at fx_rate 280 -> base 5,040,000 minor (PKR 50,400.00). */
    public function test_spec_line_tax_converts_exactly(): void
    {
        $this->assertSame(5040000, FxConverter::toBase(18000, '280'));
    }

    /** Summing the two spec components reproduces the spec's AR figure: 28,000,000 + 5,040,000 = 33,040,000 (PKR 330,400.00). */
    public function test_spec_components_sum_to_the_spec_ar_figure(): void
    {
        $baseSubtotal = FxConverter::toBase(100000, '280');
        $baseTax = FxConverter::toBase(18000, '280');

        $this->assertSame(33040000, $baseSubtotal + $baseTax);
    }

    // -----------------------------------------------------------------
    // Half-up rounding
    // -----------------------------------------------------------------

    /** A genuine halfway case, mirroring MoneyTest's own tie example: 3333 x 1.5 = 4999.5 -> half-up rounds to 5000. */
    public function test_genuine_half_tie_rounds_up(): void
    {
        $this->assertSame(5000, FxConverter::toBase(3333, '1.5'));
    }

    /** A non-tie fractional result rounds down as expected: 333 x 1.505 = 501.165 -> rounds to 501. */
    public function test_fractional_result_rounds_half_up_to_nearest_minor_unit(): void
    {
        $this->assertSame(501, FxConverter::toBase(333, '1.505'));
    }

    /** An exact multiple needs no rounding decision at all. */
    public function test_exact_multiple_needs_no_rounding(): void
    {
        $this->assertSame(50000, FxConverter::toBase(1000, '50'));
    }

    // -----------------------------------------------------------------
    // Large value — no float drift / no overflow (bcmath, not native int/float).
    // -----------------------------------------------------------------

    public function test_large_amount_has_no_float_drift_or_overflow(): void
    {
        // 999,999,999,999 x 280 = 279,999,999,999,720 — independently
        // verified with exact integer arithmetic outside this codebase.
        $this->assertSame(279999999999720, FxConverter::toBase(999999999999, '280'));
    }

    // -----------------------------------------------------------------
    // Component-wise conversion is mandatory: converting two components and
    // summing them can genuinely differ from converting their pre-summed
    // total — the rounding trap spec §17.1 warns against. Only the
    // component-wise path matches what InvoiceService actually credits to
    // the ledger.
    // -----------------------------------------------------------------

    public function test_component_wise_sum_can_differ_from_converting_the_pre_summed_total(): void
    {
        // fx_rate 1.005. Two components of 50 doc minor each (sum 100).
        $rate = '1.005';

        // Component-wise: round_half_up(50 x 1.005) = round_half_up(50.25) = 50, for each of the two components.
        $perComponent = FxConverter::toBase(50, $rate);
        $this->assertSame(50, $perComponent);
        $componentWiseSum = $perComponent + $perComponent;
        $this->assertSame(100, $componentWiseSum);

        // Naive (WRONG) path: converting the pre-summed total directly.
        // round_half_up(100 x 1.005) = round_half_up(100.5) = 101 (a genuine half-up tie).
        $naiveTotal = FxConverter::toBase(100, $rate);
        $this->assertSame(101, $naiveTotal);

        // The two paths disagree by exactly one minor unit — proving the
        // component-wise path (100, matching what's actually credited to two
        // 50-unit income lines) is NOT interchangeable with converting the
        // total in one shot (101, which corresponds to nothing actually
        // posted anywhere and would leave an unbalanced entry if used as the
        // AR debit).
        $this->assertNotSame($componentWiseSum, $naiveTotal);
    }

    // -----------------------------------------------------------------
    // Guards
    // -----------------------------------------------------------------

    public function test_negative_doc_minor_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FxConverter::toBase(-1, '280');
    }

    public function test_zero_rate_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FxConverter::toBase(1000, '0');
    }

    public function test_negative_rate_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FxConverter::toBase(1000, '-280');
    }

    public function test_rate_with_nine_decimal_places_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FxConverter::toBase(1000, '280.123456789');
    }

    /** A rate at exactly the 8-decimal boundary must still round correctly, not throw. */
    public function test_rate_with_exactly_eight_decimal_places_still_rounds_correctly(): void
    {
        $this->assertSame(28000000, FxConverter::toBase(100000, '280.00000000'));
    }

    public function test_non_numeric_rate_throws_invalid_argument_exception_not_a_bcmath_value_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FxConverter::toBase(1000, 'abc');
    }

    public function test_zero_doc_minor_converts_to_zero_regardless_of_rate(): void
    {
        $this->assertSame(0, FxConverter::toBase(0, '280'));
    }
}
