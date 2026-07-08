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
        formatter = new Intl.NumberFormat(LOCALE, { style: 'currency', currency });
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
