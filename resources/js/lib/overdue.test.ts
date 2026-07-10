import { describe, expect, it } from 'vitest';
import { isBillOverdue } from './overdue';

describe('isBillOverdue', () => {
    const TODAY = '2026-07-10';

    it.each([
        ['received', '2026-07-09'],
        ['partially_paid', '2026-01-01'],
    ])('is overdue when %s and due_date %s is strictly before today', (status, dueDate) => {
        expect(isBillOverdue({ status, due_date: dueDate }, TODAY)).toBe(true);
    });

    it('is not overdue when due today (strictly-less-than, not less-or-equal)', () => {
        expect(isBillOverdue({ status: 'received', due_date: TODAY }, TODAY)).toBe(false);
    });

    it('is not overdue when due in the future', () => {
        expect(isBillOverdue({ status: 'received', due_date: '2026-08-01' }, TODAY)).toBe(false);
    });

    it.each(['draft', 'paid', 'void'])('is never overdue for status %s, regardless of due_date', (status) => {
        expect(isBillOverdue({ status, due_date: '2020-01-01' }, TODAY)).toBe(false);
    });

    it('is not overdue when due_date is null', () => {
        expect(isBillOverdue({ status: 'partially_paid', due_date: null }, TODAY)).toBe(false);
    });
});
