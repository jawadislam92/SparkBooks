import { describe, expect, it } from 'vitest';
import { hasTextColorClass } from './textColor';

describe('hasTextColorClass', () => {
    it('detects design-system text colors', () => {
        expect(hasTextColorClass('text-clay')).toBe(true);
        expect(hasTextColorClass('text-spark')).toBe(true);
        expect(hasTextColorClass('text-ink')).toBe(true);
        expect(hasTextColorClass('text-lagoon')).toBe(true);
        expect(hasTextColorClass('text-amber')).toBe(true);
    });

    it('detects colors with a numeric shade suffix', () => {
        expect(hasTextColorClass('text-ink-700')).toBe(true);
        expect(hasTextColorClass('text-spark-600')).toBe(true);
        expect(hasTextColorClass('text-amber-50')).toBe(true);
    });

    it('detects a color anywhere in a multi-class string', () => {
        expect(hasTextColorClass('font-semibold text-clay')).toBe(true);
        expect(hasTextColorClass('text-clay font-semibold')).toBe(true);
        expect(hasTextColorClass('mt-2 font-bold text-spark tracking-tight')).toBe(true);
    });

    it('does NOT treat text size / alignment / wrap utilities as colors', () => {
        expect(hasTextColorClass('text-sm')).toBe(false);
        expect(hasTextColorClass('text-base')).toBe(false);
        expect(hasTextColorClass('text-2xl')).toBe(false);
        expect(hasTextColorClass('text-left')).toBe(false);
        expect(hasTextColorClass('text-right')).toBe(false);
        expect(hasTextColorClass('text-center')).toBe(false);
        expect(hasTextColorClass('text-lg text-left')).toBe(false);
    });

    it('returns false for the empty string and color-free classes', () => {
        expect(hasTextColorClass('')).toBe(false);
        expect(hasTextColorClass('font-mono tabular-nums mt-2')).toBe(false);
    });

    it('does not match a color name that is only a substring of another token', () => {
        // A guard against loose matching: these are not real color utilities.
        expect(hasTextColorClass('context-menu')).toBe(false);
        expect(hasTextColorClass('subtext-ink')).toBe(false);
    });
});
