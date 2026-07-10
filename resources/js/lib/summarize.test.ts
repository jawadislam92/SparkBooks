import { describe, expect, it } from 'vitest';
import { summarizeList } from './summarize';

describe('summarizeList', () => {
    it('renders an em dash for an empty list', () => {
        expect(summarizeList([])).toBe('—');
    });

    it('joins every item when at or under the visible cap', () => {
        expect(summarizeList(['Travel'])).toBe('Travel');
        expect(summarizeList(['Travel', 'Meals'])).toBe('Travel, Meals');
    });

    it('truncates with a "+N more" suffix beyond the visible cap', () => {
        expect(summarizeList(['Travel', 'Meals', 'Office Supplies'])).toBe('Travel, Meals +1 more');
        expect(summarizeList(['A', 'B', 'C', 'D'])).toBe('A, B +2 more');
    });

    it('honors a custom maxVisible', () => {
        expect(summarizeList(['A', 'B', 'C'], 1)).toBe('A +2 more');
        expect(summarizeList(['A', 'B', 'C'], 3)).toBe('A, B, C');
    });
});
