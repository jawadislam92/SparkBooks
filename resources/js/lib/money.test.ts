import { describe, expect, it } from 'vitest';
import {
    computeLineTax,
    formatMoney,
    multiplyMinorByQuantity,
    parseMoneyInput,
} from './money';

// Exact expected strings for the output of `formatMoney`, which pins the
// Intl.NumberFormat to exactly 2 fraction digits (minimum + maximum). Because
// the digit count is forced in code (not inherited from per-ICU CLDR
// defaults), these strings are stable across Node/ICU versions — the earlier
// fragility ("re-capture if the ICU version changes") is gone: the ledger
// stores 2-decimal minor units, so display is deliberately 2 digits always.
//
// IMPORTANT: PKR's Intl output separates "Rs" from the digits with a
// NON-BREAKING SPACE (U+00A0), not a regular space — confirmed by inspecting
// the code points of the actual runtime output (`Rs` `a0` `0` `.` `0` `0`).
// Built via String.fromCharCode(0xa0) below to avoid any ambiguity between a
// literal U+00A0 byte and a regular space in this source file.
const NBSP = String.fromCharCode(0xa0);

describe('formatMoney', () => {
    it.each([
        [0, `Rs${NBSP}0.00`],
        [1, `Rs${NBSP}0.01`],
        [150050, `Rs${NBSP}1,500.50`],
        [999999999999, `Rs${NBSP}9,999,999,999.99`],
    ])('formats %i minor units of PKR as %s', (minor, expected) => {
        expect(formatMoney(minor, 'PKR')).toBe(expected);
    });

    it.each([
        [0, 'US$0.00'],
        [1, 'US$0.01'],
        [150050, 'US$1,500.50'],
        [999999999999, 'US$9,999,999,999.99'],
    ])('formats %i minor units of USD as %s', (minor, expected) => {
        expect(formatMoney(minor, 'USD')).toBe(expected);
    });

    // Determinism guard: the fraction part must ALWAYS be exactly 2 digits,
    // regardless of the viewer's ICU/CLDR currency defaults. This is what
    // stops paisa/cents being silently rounded off the display. The regex
    // asserts a '.' followed by exactly two digits, then a non-digit or
    // end-of-string (so a 3rd fraction digit would fail).
    const TWO_FRACTION_DIGITS = /\.\d{2}(\D|$)/;

    it.each([
        [150000, 'PKR', '.00'],
        [150050, 'PKR', '.50'],
        [150000, 'USD', '.00'],
        [150050, 'USD', '.50'],
    ])(
        'renders %i %s with exactly two fraction digits (contains %s)',
        (minor, currency, ending) => {
            const output = formatMoney(minor, currency);
            expect(output).toContain(ending);
            expect(output).toMatch(TWO_FRACTION_DIGITS);
        },
    );
});

describe('parseMoneyInput', () => {
    it.each([
        ['1,500.50', 150050],
        ['1500', 150000],
        ['0.5', 50],
        ['1.999', null],
        ['abc', null],
        ['', null],
    ])('parses %j as %j', (text, expected) => {
        expect(parseMoneyInput(text)).toBe(expected);
    });

    it("treats a lone '.' as null", () => {
        expect(parseMoneyInput('.')).toBeNull();
    });

    it.each([0, 1, 150050, 999999999999])(
        'round-trips %i minor units through formatMoney(PKR) and back',
        (n) => {
            const digitsOnly = formatMoney(n, 'PKR').replace(/[^\d.]/g, '');
            expect(parseMoneyInput(digitsOnly)).toBe(n);
        },
    );
});

// Mirrors app/Support/Money's own worked examples (see MoneyTest.php) — the
// invoice line editor's client-side preview must match the server's
// bcmath-computed result exactly.
describe('multiplyMinorByQuantity', () => {
    it.each([
        [10000, '2.5', 25000],
        [3333, '3', 9999],
        [3333, '1.5', 5000], // 4999.5 -> half-up 5000, a genuine tie.
        [12345, '0.0001', 1], // 1.2345 -> half-up 1.
        [10000, '0', 0],
    ])('multiplyMinorByQuantity(%i, %j) === %i', (minor, quantity, expected) => {
        expect(multiplyMinorByQuantity(minor, quantity)).toBe(expected);
    });

    it.each(['-2.5', '2.50001', 'abc', ''])(
        'returns null for an invalid quantity %j',
        (quantity) => {
            expect(multiplyMinorByQuantity(10000, quantity)).toBeNull();
        },
    );
});

describe('computeLineTax', () => {
    it.each([
        [10000, '18', 1800],
        [3333, '18', 600], // 599.94 -> half-up 600, the brief's own worked example.
        [100, '0.5', 1], // exact 0.5 minor-unit tie -> half-up 1.
        [999999, '0', 0],
    ])('computeLineTax(%i, %j) === %i', (subtotal, rate, expected) => {
        expect(computeLineTax(subtotal, rate)).toBe(expected);
    });
});
