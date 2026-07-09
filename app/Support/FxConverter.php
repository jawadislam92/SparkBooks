<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Converts a document-currency minor-unit amount to base-currency minor
 * units at a given FX rate (rate = base units per 1 doc-currency unit — e.g.
 * a USD->PKR fx_rate of 280 means 1 USD = PKR 280), rounded HALF-UP to the
 * nearest base minor unit — posting rule P1fx (spec §17.1). Pure: no DB, no
 * float, bcmath only, exactly mirroring Money::taxMinorUnits'
 * scale/multiply/half-up-divide discipline (this file duplicates that small
 * amount of arithmetic rather than reaching into Money's private guards,
 * since both are meant to stand alone as pure conversion primitives).
 *
 * **Component-wise conversion is mandatory, not a style choice.** Callers
 * MUST convert EACH component (each line's subtotal, each line's tax)
 * separately via toBase() and sum the integer results themselves — never
 * convert a pre-summed document total in one shot. The two paths can
 * genuinely disagree once rounding is involved (see FxConverterTest's
 * component-wise-vs-naive-total case: two 50-minor components at fx_rate
 * 1.005 each round to 50, summing to 100; converting the pre-summed 100
 * directly rounds to 101 instead). Only the component-wise sum matches what
 * is actually credited to the ledger (Σ per-line income credits + the tax
 * credit), which is what keeps InvoiceService's P1fx entry balanced by
 * construction — an AR debit built from a naive total would have no
 * corresponding credit sum and the entry would not balance.
 */
class FxConverter
{
    /** invoices.fx_rate is DECIMAL(16,8) — up to 8 fractional digits. */
    private const RATE_DECIMALS = 8;

    /** 10 ** RATE_DECIMALS, kept as a bcmath-ready string. */
    private const RATE_SCALE_FACTOR = '100000000';

    /**
     * base = round_half_up(docMinor * fxRate). Every intermediate value is an
     * exact integer bc-string:
     *   1. fxRate is scaled to an integer by multiplying by 10^8 (exact,
     *      because it has at most 8 decimal places) — e.g. "1.5" -> "150000000".
     *   2. numerator = docMinor * rateScaledInt, via bcmul (arbitrary
     *      precision — never overflows into float the way native `*` does
     *      once the product exceeds PHP_INT_MAX).
     *   3. divisor = 10^8 — undoes the rate scale.
     *   4. The final result is round_half_up(numerator / divisor), computed
     *      as an integer division: floor((2*numerator + divisor) /
     *      (2*divisor)) — valid because docMinor and fxRate are both
     *      guaranteed non-negative below.
     */
    public static function toBase(int $docMinor, string $fxRate): int
    {
        if ($docMinor < 0) {
            throw new InvalidArgumentException('docMinor must not be negative.');
        }

        $trimmedRate = self::assertPositiveDecimal($fxRate);

        $rateScaledInt = bcmul($trimmedRate, self::RATE_SCALE_FACTOR, 0);
        $numerator = bcmul((string) $docMinor, $rateScaledInt);

        return self::bcHalfUpDivide($numerator, self::RATE_SCALE_FACTOR);
    }

    /**
     * fxRate must be a strictly positive plain decimal string with at most
     * RATE_DECIMALS fractional digits: a zero or negative rate would make
     * conversion meaningless (and invert debit/credit sign assumptions
     * throughout InvoiceService::send), and a malformed string (non-numeric,
     * scientific notation, more than 8dp) would silently lose precision in
     * the scaling step above — so every shape violation throws loudly here,
     * before any bcmath call ever sees the raw string (mirrors
     * Money::assertNonNegativeDecimal's own carried-in hardening).
     */
    private static function assertPositiveDecimal(string $value): string
    {
        $trimmed = trim($value);

        if (preg_match('/^\d+(\.\d+)?$/', $trimmed) !== 1) {
            throw new InvalidArgumentException('fxRate must be a valid positive decimal number.');
        }

        $fractionPart = explode('.', $trimmed, 2)[1] ?? '';
        if (strlen($fractionPart) > self::RATE_DECIMALS) {
            throw new InvalidArgumentException('fxRate must not have more than '.self::RATE_DECIMALS.' decimal places.');
        }

        if (bccomp($trimmed, '0', self::RATE_DECIMALS) <= 0) {
            throw new InvalidArgumentException('fxRate must be greater than zero.');
        }

        return $trimmed;
    }

    /**
     * Half-up integer division of two non-negative bc-string operands:
     * floor((2*numerator + divisor) / (2*divisor)) — identical identity to
     * Money::bcHalfUpDivide. bcdiv truncates toward zero at scale 0, which is
     * the same as floor for non-negative operands — and both operands are
     * always non-negative here.
     */
    private static function bcHalfUpDivide(string $numerator, string $divisor): int
    {
        $doubledNumeratorPlusDivisor = bcadd(bcmul($numerator, '2'), $divisor);
        $doubledDivisor = bcmul($divisor, '2');

        return (int) bcdiv($doubledNumeratorPlusDivisor, $doubledDivisor, 0);
    }
}
