<?php

namespace App\Support;

/**
 * Money is one of the "iron rules" surfaces (PROJECT_MAP §7/§9): every money
 * value is an integer number of minor units end-to-end, and nothing on a
 * money path may touch float/double — CI greps app/ for those casts and
 * fails the build. This file is the reason that rule is enforceable at all
 * for tax math: every computation below goes through bcmath (arbitrary-
 * precision decimal *strings*), never native PHP int/float arithmetic, so
 * there is no point where a product could silently overflow PHP_INT_MAX and
 * get promoted to a float (which is exactly what `$int1 * $int2` does once
 * the result exceeds PHP's native integer range).
 */
class Money
{
    /** tax_rates.rate is DECIMAL(7,4) — a percent value with up to 4 fractional digits. */
    private const RATE_DECIMALS = 4;

    /** 10 ** RATE_DECIMALS, kept as a bcmath-ready string. */
    private const RATE_SCALE_FACTOR = '10000';

    /**
     * Tax on a net line amount (in minor units) at a percent rate string
     * (e.g. "18", "18.5", "18.0000"), rounded HALF-UP to the nearest minor
     * unit: tax = round_half_up(lineNetMinor * rate / 100).
     *
     * Every intermediate value is an exact integer bc-string:
     *   1. The rate is scaled to an integer by multiplying by 10^4 (exact,
     *      because the rate has at most 4 decimal places) — e.g.
     *      "18.5" -> "185000".
     *   2. numerator = lineNetMinor * rateScaledInt, via bcmul (arbitrary
     *      precision — never overflows into float the way `*` on native
     *      ints does past PHP_INT_MAX).
     *   3. divisor = 100 * 10^4 = 1,000,000 — undoes the rate scale AND the
     *      percent-to-fraction division in a single step.
     *   4. The final result is round_half_up(numerator / divisor), computed
     *      as an integer division: floor((2*numerator + divisor) /
     *      (2*divisor)). That identity holds because every operand here is
     *      non-negative (rates are validated 0-100; minor-unit amounts are
     *      never negative).
     */
    public static function taxMinorUnits(int $lineNetMinor, string $ratePercent): int
    {
        $rateScaledInt = self::scaleRateToInteger($ratePercent);
        $numerator = bcmul((string) $lineNetMinor, $rateScaledInt);
        $divisor = bcmul('100', self::RATE_SCALE_FACTOR);

        return self::bcHalfUpDivide($numerator, $divisor);
    }

    /**
     * Mirrors resources/js/lib/money.ts::parseMoneyInput exactly: strips
     * thousands-grouping commas, requires a plain non-negative decimal with
     * at most 2 fractional digits, and returns integer minor units — never
     * via floatval or a float cast, which can't represent most base-10
     * decimals exactly (see money.ts's own doc-comment for concrete examples
     * of that failure mode). Returns null for empty input, a lone '.',
     * anything with more than 2 fractional digits, or anything that isn't a
     * plain non-negative decimal.
     */
    public static function parseToMinor(string $input): ?int
    {
        $cleaned = str_replace(',', '', trim($input));

        if ($cleaned === '' || $cleaned === '.' || preg_match('/^\d*\.?\d*$/', $cleaned) !== 1) {
            return null;
        }

        [$wholePart, $fractionPart] = array_pad(explode('.', $cleaned, 2), 2, '');

        if (strlen($fractionPart) > 2) {
            return null;
        }

        if ($wholePart === '' && $fractionPart === '') {
            return null;
        }

        $wholeUnits = $wholePart === '' ? 0 : (int) $wholePart;
        $fractionUnits = (int) str_pad($fractionPart, 2, '0');

        return $wholeUnits * 100 + $fractionUnits;
    }

    /**
     * Scales a percent-rate decimal string (up to RATE_DECIMALS places) to
     * an exact integer bc-string representing rate * 10^RATE_DECIMALS.
     */
    private static function scaleRateToInteger(string $ratePercent): string
    {
        return bcmul(trim($ratePercent), self::RATE_SCALE_FACTOR, 0);
    }

    /**
     * Half-up integer division of two non-negative bc-string operands:
     * floor((2*numerator + divisor) / (2*divisor)). bcdiv truncates toward
     * zero at scale 0, which is the same as floor for non-negative operands
     * — and both operands are always non-negative here.
     */
    private static function bcHalfUpDivide(string $numerator, string $divisor): int
    {
        $doubledNumeratorPlusDivisor = bcadd(bcmul($numerator, '2'), $divisor);
        $doubledDivisor = bcmul($divisor, '2');

        return (int) bcdiv($doubledNumeratorPlusDivisor, $doubledDivisor, 0);
    }
}
