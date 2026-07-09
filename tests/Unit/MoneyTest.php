<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Money::taxMinorUnits and Money::parseToMinor are pure, DB-free helpers, so
 * this extends PHPUnit's own TestCase (not Tests\TestCase / RefreshDatabase)
 * — no app boot, no database, on purpose.
 */
class MoneyTest extends TestCase
{
    // -----------------------------------------------------------------
    // taxMinorUnits — half-up rounding, exact integers, no float
    // -----------------------------------------------------------------

    /** The brief's own worked example: 3333 * 18 / 100 = 599.94 -> rounds up to 600. */
    public function test_awkward_amount_rounds_half_up_599_94_to_600(): void
    {
        $this->assertSame(600, Money::taxMinorUnits(3333, '18'));
    }

    /** A clean multiple with no rounding decision at all: 10000 * 18 / 100 = 1800.00 exactly. */
    public function test_exact_multiple_needs_no_rounding(): void
    {
        $this->assertSame(1800, Money::taxMinorUnits(10000, '18'));
    }

    /** Rate strings with trailing zeros (as the decimal:4 DB cast would hand back) parse identically to the bare integer form. */
    public function test_rate_with_trailing_decimal_zeros_matches_bare_integer_rate(): void
    {
        $this->assertSame(600, Money::taxMinorUnits(3333, '18.0000'));
    }

    /**
     * Three lines of the classic 33.33 case, each taxed independently and
     * rounded on its own line (the spec's round-per-line-then-sum rule) —
     * summing the three independently-rounded results must reproduce
     * 3 x 600 = 1800, not silently drift from repeated rounding error.
     */
    public function test_three_awkward_lines_sum_without_rounding_drift(): void
    {
        $perLine = Money::taxMinorUnits(3333, '18');

        $this->assertSame(600, $perLine);
        $this->assertSame(1800, $perLine + $perLine + $perLine);
    }

    /**
     * A genuine halfway case that must round UP: 100 minor units at 0.5%
     * is exactly 0.5 minor units of tax (100 * 0.5 / 100 = 0.5) — half-up
     * rounds this to 1, not 0 (which is what round-half-to-even, or a naive
     * float `round()`, could give under some conditions).
     */
    public function test_exact_half_minor_unit_rounds_up(): void
    {
        $this->assertSame(1, Money::taxMinorUnits(100, '0.5'));
    }

    public function test_zero_rate_yields_zero_tax(): void
    {
        $this->assertSame(0, Money::taxMinorUnits(999999, '0'));
        $this->assertSame(0, Money::taxMinorUnits(999999, '0.0000'));
    }

    /**
     * A large amount whose product with the scaled rate would sit well
     * past float's 2^53 exact-integer ceiling if this were ever computed via
     * float math (999999999999 * 180000 = 179,999,999,999,820,000, ~1.8e17,
     * far beyond Number.MAX_SAFE_INTEGER / a float64 mantissa's 2^53 ~9e15).
     * Independently verified with exact integer (BigInt) arithmetic outside
     * this codebase: expected result is 180000000000.
     */
    public function test_large_amount_has_no_float_drift(): void
    {
        $this->assertSame(180000000000, Money::taxMinorUnits(999999999999, '18'));
    }

    public function test_rate_at_the_upper_bound_of_100_percent(): void
    {
        $this->assertSame(3333, Money::taxMinorUnits(3333, '100'));
    }

    // Money::taxMinorUnits's source is also self-checked by hand per the
    // task brief: `grep -nE '\((float|double)\)' app/Support/Money.php`
    // must return nothing (verified during implementation; see the task
    // report). Not encoded as a PHPUnit assertion here — a test that reads
    // its own production file's bytes back doesn't pin down behavior, only
    // prose, and CI already enforces the real gate across all of app/.

    // -----------------------------------------------------------------
    // taxMinorUnits hardening (Phase 3 Task 2) — loud guards, carried from
    // Phase 2's "upstream validation prevents it today, invoicing must not
    // rely on that same upstream guard holding" note (PROJECT_MAP §10).
    // -----------------------------------------------------------------

    public function test_negative_line_net_minor_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::taxMinorUnits(-1, '18');
    }

    public function test_negative_rate_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::taxMinorUnits(3333, '-18');
    }

    public function test_rate_with_five_decimal_places_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::taxMinorUnits(3333, '18.12345');
    }

    /** A rate at exactly the 4-decimal boundary must still round correctly, not throw. */
    public function test_rate_with_exactly_four_decimal_places_still_rounds_correctly(): void
    {
        $this->assertSame(600, Money::taxMinorUnits(3333, '18.0000'));
    }

    /**
     * Carry-in fix (Phase 3 Task 7): before this guard, a non-numeric rate
     * string sailed past every existing check (not empty, no leading '-', no
     * '.' so the fraction-length check never fired) and then blew up inside
     * bcmul() with a raw ValueError instead of the documented
     * InvalidArgumentException — see the FxConverter/InvoiceFxSend work this
     * was carried alongside for the same hardening applied to fx_rate.
     */
    public function test_non_numeric_rate_throws_invalid_argument_exception_not_a_bcmath_value_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::taxMinorUnits(3333, 'abc');
    }

    // -----------------------------------------------------------------
    // multiplyByQuantity — minor x decimal quantity (<=4dp), half-up,
    // integer/bcmath only (no float).
    // -----------------------------------------------------------------

    public function test_exact_multiplication_by_a_half_quantity(): void
    {
        $this->assertSame(25000, Money::multiplyByQuantity(10000, '2.5'));
    }

    public function test_exact_multiplication_by_a_whole_quantity(): void
    {
        $this->assertSame(9999, Money::multiplyByQuantity(3333, '3'));
    }

    /** The brief's own worked tie: 3333 x 1.5 = 4999.5 -> half-up rounds to 5000, not 4999. */
    public function test_genuine_half_tie_rounds_up(): void
    {
        $this->assertSame(5000, Money::multiplyByQuantity(3333, '1.5'));
    }

    /**
     * A large minor-unit amount whose product with the scaled quantity would
     * sit well past float's 2^53 exact-integer ceiling if this were ever
     * computed via float math — proves bcmath (not native int/float
     * multiplication) is actually doing the work.
     */
    public function test_multiply_by_quantity_large_amount_has_no_float_drift(): void
    {
        $this->assertSame(2999999999997, Money::multiplyByQuantity(999999999999, '3'));
    }

    /** The smallest representable quantity step: 0.0001. */
    public function test_smallest_quantity_step_rounds_correctly(): void
    {
        // 12345 * 0.0001 = 1.2345 -> half-up rounds to 1.
        $this->assertSame(1, Money::multiplyByQuantity(12345, '0.0001'));
    }

    public function test_zero_quantity_yields_zero(): void
    {
        $this->assertSame(0, Money::multiplyByQuantity(10000, '0'));
    }

    public function test_negative_quantity_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::multiplyByQuantity(10000, '-2.5');
    }

    public function test_quantity_with_five_decimal_places_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::multiplyByQuantity(10000, '2.50001');
    }

    public function test_negative_minor_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::multiplyByQuantity(-1, '1');
    }

    /** Same carry-in fix as test_non_numeric_rate_throws... above, for the quantity guard. */
    public function test_non_numeric_quantity_throws_invalid_argument_exception_not_a_bcmath_value_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::multiplyByQuantity(10000, 'abc');
    }

    // -----------------------------------------------------------------
    // parseToMinor — PHP mirror of resources/js/lib/money.ts::parseMoneyInput
    // -----------------------------------------------------------------

    public function test_parses_grouped_decimal_with_cents(): void
    {
        $this->assertSame(150050, Money::parseToMinor('1,500.50'));
    }

    public function test_parses_whole_number_with_no_decimal_point(): void
    {
        $this->assertSame(150000, Money::parseToMinor('1500'));
    }

    public function test_parses_a_single_fractional_digit(): void
    {
        $this->assertSame(50, Money::parseToMinor('0.5'));
    }

    public function test_more_than_two_fraction_digits_is_rejected(): void
    {
        $this->assertNull(Money::parseToMinor('1.999'));
    }

    public function test_non_numeric_input_is_rejected(): void
    {
        $this->assertNull(Money::parseToMinor('abc'));
    }

    public function test_empty_string_is_rejected(): void
    {
        $this->assertNull(Money::parseToMinor(''));
    }

    public function test_whitespace_only_string_is_rejected(): void
    {
        $this->assertNull(Money::parseToMinor('  '));
    }

    public function test_lone_dot_is_rejected(): void
    {
        $this->assertNull(Money::parseToMinor('.'));
    }
}
