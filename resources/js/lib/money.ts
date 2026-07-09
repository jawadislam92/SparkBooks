/**
 * Money is always represented in "minor units" (integer cents/paisa) outside
 * of display formatting — never as a floating-point major-unit decimal.
 * Converting user-typed decimals to minor units must be done via string/integer
 * arithmetic, never `parseFloat(...) * 100`, because IEEE-754 floats cannot
 * represent most base-10 decimals exactly. For example, in this exact runtime:
 *   parseFloat('0.29') * 100        === 28.999999999999996  (not 29)
 *   parseFloat('1.005') * 100       === 100.49999999999999  (not 100.5)
 *   parseFloat('19.99') * 100       === 1998.9999999999998  (not 1999)
 * Any of those would silently corrupt a posted ledger amount by a cent.
 */

const LOCALE = 'en-PK';

const formatterCache = new Map<string, Intl.NumberFormat>();

function getFormatter(currency: string): Intl.NumberFormat {
    let formatter = formatterCache.get(currency);

    if (!formatter) {
        // Fraction digits are pinned to exactly 2, deliberately: the ledger
        // stores 2-decimal minor units, so display must always show both.
        // Without this, Intl.NumberFormat inherits per-currency CLDR defaults
        // that vary by the viewer's ICU version (e.g. some ICU data renders
        // PKR with 0 fraction digits), which would silently drop paisa/cents
        // off the DISPLAY while the stored value stayed exact. Forcing 2
        // digits makes every money value render identically across browsers.
        formatter = new Intl.NumberFormat(LOCALE, {
            style: 'currency',
            currency,
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
        formatterCache.set(currency, formatter);
    }

    return formatter;
}

/**
 * Formats an integer minor-unit amount (e.g. cents, paisa) as a currency
 * string using `Intl.NumberFormat('en-PK', { style: 'currency', currency })`.
 */
export function formatMoney(minor: number, currency: string): string {
    return getFormatter(currency).format(minor / 100);
}

const MONEY_INPUT_PATTERN = /^\d*\.?\d*$/;

/**
 * Parses a user-typed decimal amount (optionally with thousands separators,
 * e.g. "1,500.50") into integer minor units, using only string splitting and
 * integer arithmetic — never `parseFloat` followed by multiplication.
 *
 * Returns `null` for empty input, a lone '.', anything with more than 2
 * fractional digits, or anything that isn't a plain non-negative decimal.
 */
export function parseMoneyInput(text: string): number | null {
    const cleaned = text.trim().replace(/,/g, '');

    if (cleaned === '' || cleaned === '.' || !MONEY_INPUT_PATTERN.test(cleaned)) {
        return null;
    }

    const [wholePart = '', fractionPart = ''] = cleaned.split('.');

    if (fractionPart.length > 2) {
        return null;
    }

    if (wholePart === '' && fractionPart === '') {
        return null;
    }

    const wholeUnits = wholePart === '' ? 0 : Number(wholePart);
    const fractionUnits = Number(fractionPart.padEnd(2, '0'));

    return wholeUnits * 100 + fractionUnits;
}

/**
 * The inverse of parseMoneyInput: an integer minor-unit amount back into the
 * plain decimal string an amount `<input>` expects (e.g. 150050 -> "1500.50").
 * Used to populate the opening-balances form fields from server-parsed CSV
 * rows (which arrive as minor-unit integers, per the no-float money rule) so
 * the same text inputs and parseMoneyInput round-trip on submit stay in play
 * — never a display-only float division that could round differently than
 * the integer math everywhere else on this path.
 */
export function minorToDecimalString(minor: number): string {
    const sign = minor < 0 ? '-' : '';
    const abs = Math.abs(minor);
    const whole = Math.trunc(abs / 100);
    const fraction = abs % 100;

    return `${sign}${whole}.${String(fraction).padStart(2, '0')}`;
}

/**
 * The invoice line editor's live-preview math (subtotal/tax/total shown
 * while typing). These mirror app/Support/Money's bcmath helpers
 * (multiplyByQuantity, taxMinorUnits) step for step — scale a decimal
 * string to an exact integer via string splitting, then do integer-only
 * arithmetic — so the preview matches the server's authoritative
 * (bcmath-computed) result exactly for any realistic invoice amount. This is
 * a client-only PREVIEW, not the money-of-record path (the actual stored
 * totals are always recomputed server-side by InvoiceCalculator), but it
 * still avoids parseFloat for the same reason parseMoneyInput does: a
 * fractional-quantity x fractional-rate float multiplication can drift from
 * the exact bcmath result the server computes.
 */
const QUANTITY_INPUT_PATTERN = /^\d*\.?\d*$/;

/**
 * Scales a non-negative decimal string with up to `maxDecimals` fractional
 * digits into an exact integer (value x 10^maxDecimals), via string
 * splitting/padding only. Returns null for anything that isn't a plain
 * non-negative decimal, or that has more fractional digits than allowed.
 */
function scaleDecimalToInteger(text: string, maxDecimals: number): number | null {
    const cleaned = text.trim();

    if (cleaned === '' || !QUANTITY_INPUT_PATTERN.test(cleaned)) {
        return null;
    }

    const [wholePart = '', fractionPart = ''] = cleaned.split('.');

    if (fractionPart.length > maxDecimals) {
        return null;
    }

    if (wholePart === '' && fractionPart === '') {
        return null;
    }

    const wholeUnits = wholePart === '' ? 0 : Number(wholePart);
    const fractionUnits = Number(fractionPart.padEnd(maxDecimals, '0'));
    const scale = 10 ** maxDecimals;

    return wholeUnits * scale + fractionUnits;
}

/** Half-up integer division, mirroring Money::bcHalfUpDivide's identity for non-negative operands. */
function halfUpDivide(numerator: number, divisor: number): number {
    return Math.floor((2 * numerator + divisor) / (2 * divisor));
}

/**
 * Mirrors Money::multiplyByQuantity: a minor-unit amount x a decimal
 * quantity (up to 4dp), half-up to the nearest minor unit. Returns null for
 * a quantity that isn't a plain non-negative decimal with at most 4
 * fractional digits (mirrors the shape StoreInvoiceRequest itself accepts).
 */
export function multiplyMinorByQuantity(minor: number, quantityText: string): number | null {
    const scaledQuantity = scaleDecimalToInteger(quantityText, 4);
    if (scaledQuantity === null) {
        return null;
    }

    return halfUpDivide(minor * scaledQuantity, 10000);
}

/**
 * Mirrors Money::taxMinorUnits: tax on a net line amount (minor units) at a
 * percent rate string (up to 4dp, e.g. "18", "18.5"), half-up to the nearest
 * minor unit. Returns 0 for a rate that doesn't parse (the invoice line
 * editor only ever passes rates straight from the server-provided tax rate
 * list, so this is a defensive fallback, not an expected path).
 */
export function computeLineTax(lineSubtotalMinor: number, ratePercent: string): number {
    const scaledRate = scaleDecimalToInteger(ratePercent, 4);
    if (scaledRate === null) {
        return 0;
    }

    return halfUpDivide(lineSubtotalMinor * scaledRate, 1000000);
}
