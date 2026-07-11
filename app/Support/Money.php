<?php

namespace App\Support;

use InvalidArgumentException;

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

    /** invoice_lines.quantity is DECIMAL(12,4) — up to 4 fractional digits. */
    private const QUANTITY_DECIMALS = 4;

    /** 10 ** QUANTITY_DECIMALS, kept as a bcmath-ready string. */
    private const QUANTITY_SCALE_FACTOR = '10000';

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
     *
     * Hardened (Phase 3 — carried forward from Phase 2, PROJECT_MAP §10):
     * invoicing is the first real caller of this method against
     * user-influenced input (a tax_rate_id resolves to a DB-stored rate, but
     * lineNetMinor is itself derived from client-typed quantity/unit_price),
     * so this now throws loudly instead of silently truncating a negative
     * amount/rate or a rate with more than 4 decimal places — the identity
     * in step 4 above is only valid for non-negative operands, and a >4dp
     * rate would silently lose precision in step 1's exact scaling.
     */
    public static function taxMinorUnits(int $lineNetMinor, string $ratePercent): int
    {
        if ($lineNetMinor < 0) {
            throw new InvalidArgumentException('lineNetMinor must not be negative.');
        }

        $trimmedRate = self::assertNonNegativeDecimal($ratePercent, self::RATE_DECIMALS, 'ratePercent');

        $rateScaledInt = self::scaleRateToInteger($trimmedRate);
        $numerator = bcmul((string) $lineNetMinor, $rateScaledInt);
        $divisor = bcmul('100', self::RATE_SCALE_FACTOR);

        return self::bcHalfUpDivide($numerator, $divisor);
    }

    /**
     * Multiplies a minor-unit amount by a decimal quantity (up to 4 decimal
     * places, e.g. invoice_lines.quantity's DECIMAL(12,4)), rounded HALF-UP
     * to the nearest minor unit — integer/bcmath only, exactly like
     * taxMinorUnits above:
     *   1. The quantity is scaled to an integer by multiplying by 10^4
     *      (exact, because it has at most 4 decimal places) — e.g.
     *      "2.5" -> "25000".
     *   2. numerator = minor * quantityScaledInt, via bcmul.
     *   3. divisor = 10^4 — undoes the quantity scale.
     *   4. round_half_up(numerator / divisor), via the same
     *      floor((2*numerator + divisor) / (2*divisor)) identity, valid
     *      because both operands are guaranteed non-negative below.
     */
    public static function multiplyByQuantity(int $minor, string $quantity): int
    {
        if ($minor < 0) {
            throw new InvalidArgumentException('minor must not be negative.');
        }

        $trimmedQuantity = self::assertNonNegativeDecimal($quantity, self::QUANTITY_DECIMALS, 'quantity');

        $quantityScaledInt = bcmul($trimmedQuantity, self::QUANTITY_SCALE_FACTOR, 0);
        $numerator = bcmul((string) $minor, $quantityScaledInt);

        return self::bcHalfUpDivide($numerator, self::QUANTITY_SCALE_FACTOR);
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
     * Shared guard for taxMinorUnits' rate and multiplyByQuantity's quantity:
     * both are decimal *strings* that must be non-negative and have at most
     * `$maxDecimals` fractional digits, so their later bcmul-based scaling
     * step is exact rather than silently truncating precision. Deliberately
     * does NOT require the whole string to be a well-formed number beyond
     * that — both callers only ever receive strings already shaped by
     * upstream validation (a DB decimal cast, or StoreInvoiceRequest's own
     * quantity regex) — this guard exists so a caller that skips that
     * upstream validation fails loudly here instead of silently mis-rounding.
     * Returns the trimmed string for the caller to scale.
     */
    private static function assertNonNegativeDecimal(string $value, int $maxDecimals, string $label): string
    {
        $trimmed = trim($value);

        if ($trimmed === '' || str_starts_with($trimmed, '-')) {
            throw new InvalidArgumentException("{$label} must not be negative.");
        }

        // Hardened (Phase 3 Task 7 carry-in): a non-numeric string (e.g.
        // 'abc') would otherwise sail past every check below — it isn't
        // empty, doesn't start with '-', and has no '.' so the fraction-part
        // check never fires — and then blow up inside scaleRateToInteger's
        // bcmul() with a raw ValueError instead of the documented
        // InvalidArgumentException. A plain non-negative-decimal shape check
        // here closes that gap for every caller (taxMinorUnits,
        // multiplyByQuantity) before any bcmath call ever sees the string.
        if (preg_match('/^\d+(\.\d+)?$/', $trimmed) !== 1) {
            throw new InvalidArgumentException("{$label} must be a valid non-negative decimal number.");
        }

        $fractionPart = explode('.', $trimmed, 2)[1] ?? '';

        if (strlen($fractionPart) > $maxDecimals) {
            throw new InvalidArgumentException("{$label} must not have more than {$maxDecimals} decimal places.");
        }

        return $trimmed;
    }

    /**
     * Converts an integer minor-unit amount into an exact decimal string
     * using only integer division/modulo — never float math, never
     * number_format() — so an odd-paisa amount like Rs 33.33 can never
     * round-trip as "33.329999999999995". Every report's CSV export renders
     * money through this (first extracted from
     * TrialBalanceController::exportCsv in the Phase 5 reporting-foundation
     * task, so future reports don't each reinvent it). Sign-safe, though
     * report amounts are typically never negative.
     */
    public static function minorToDecimalString(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
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
