import { describe, expect, it } from 'vitest';
import { formatMoney, parseMoneyInput } from './money';

// Exact expected strings pinned from the actual runtime output of
// `Intl.NumberFormat('en-PK', { style: 'currency', currency })` — captured via:
//   node -e "console.log(new Intl.NumberFormat('en-PK',{style:'currency',currency:'PKR'}).format(1500.50))"
// Do NOT hand-guess these; re-capture if the Node/ICU version ever changes.
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
