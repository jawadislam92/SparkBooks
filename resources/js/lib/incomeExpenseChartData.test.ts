import { describe, expect, it } from 'vitest';
import {
    buildIncomeExpenseChartData,
    EXPENSE_COLOR,
    INCOME_COLOR,
} from './incomeExpenseChartData';

describe('buildIncomeExpenseChartData', () => {
    it('shapes months/income/expenses into a two-dataset Chart.js data object', () => {
        const result = buildIncomeExpenseChartData({
            labels: ['Feb 2026', 'Mar 2026', 'Apr 2026'],
            income: [10000, 0, 25000],
            expenses: [4000, 3000, 0],
        });

        expect(result.labels).toEqual(['Feb 2026', 'Mar 2026', 'Apr 2026']);
        expect(result.datasets).toHaveLength(2);

        expect(result.datasets[0]).toEqual({
            label: 'Income',
            data: [10000, 0, 25000],
            backgroundColor: INCOME_COLOR,
            borderRadius: 4,
        });
        expect(result.datasets[1]).toEqual({
            label: 'Expenses',
            data: [4000, 3000, 0],
            backgroundColor: EXPENSE_COLOR,
            borderRadius: 4,
        });
    });

    it('preserves integer minor units verbatim — never divides or floats them', () => {
        const result = buildIncomeExpenseChartData({
            labels: ['Jul 2026'],
            income: [123456],
            expenses: [1],
        });

        expect(result.datasets[0].data).toEqual([123456]);
        expect(result.datasets[1].data).toEqual([1]);
    });

    it('handles an empty (freshly onboarded) company without error', () => {
        const result = buildIncomeExpenseChartData({
            labels: ['Feb 2026', 'Mar 2026', 'Apr 2026', 'May 2026', 'Jun 2026', 'Jul 2026'],
            income: [0, 0, 0, 0, 0, 0],
            expenses: [0, 0, 0, 0, 0, 0],
        });

        expect(result.datasets[0].data).toEqual([0, 0, 0, 0, 0, 0]);
        expect(result.datasets[1].data).toEqual([0, 0, 0, 0, 0, 0]);
        expect(result.labels).toHaveLength(6);
    });
});
