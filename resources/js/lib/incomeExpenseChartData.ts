/**
 * Pure shaping of the Dashboard's income-vs-expense payload (DashboardController::
 * incomeExpenseChart() — labels + per-month income/expense minor-unit totals,
 * straight from ProfitAndLossReport's own monthly buckets) into the
 * Chart.js `data` config the IncomeExpenseChart component feeds `new Chart()`.
 *
 * Deliberately extracted as a standalone, side-effect-free function (rather
 * than inlined in the chart component) so the months->series shaping has its
 * own direct vitest coverage, mirroring resources/js/lib/overdue.ts's own
 * "shared predicate gets its own tested module" precedent.
 *
 * Colors are the two tokens tailwind.config.js defines for this exact
 * pairing — spark (income, `theme.extend.colors.spark.DEFAULT`) and clay
 * (expense, `theme.extend.colors.clay.DEFAULT`) — passed as literal hex
 * because Chart.js renders to a <canvas>, which cannot resolve Tailwind
 * utility classes or CSS custom properties the way DOM elements can. These
 * are not new arbitrary colors: they are the SAME hex values already bound
 * to the `spark`/`clay` tokens, copied here (not invented) so canvas
 * rendering can use them.
 */

export const INCOME_COLOR = '#0FA678'; // tailwind.config.js theme.extend.colors.spark.DEFAULT
export const EXPENSE_COLOR = '#D65A4A'; // tailwind.config.js theme.extend.colors.clay.DEFAULT

export interface IncomeExpenseChartPayload {
    labels: string[];
    /** Integer minor units per month, oldest -> newest (mirrors ProfitAndLossReport['months']/['income']['total_by_month']). */
    income: number[];
    /** Integer minor units per month, oldest -> newest. */
    expenses: number[];
}

export interface IncomeExpenseChartDataset {
    label: string;
    data: number[];
    backgroundColor: string;
    borderRadius: number;
}

export interface IncomeExpenseChartData {
    labels: string[];
    datasets: IncomeExpenseChartDataset[];
}

/**
 * Builds the Chart.js bar-chart `data` object. Values stay in integer minor
 * units (never divided/floated here) — display formatting (major-unit
 * currency strings) is the chart component's tooltip/tick callback's job,
 * the same "convert only at render time" split `formatMoney` already uses
 * elsewhere in this app.
 */
export function buildIncomeExpenseChartData(payload: IncomeExpenseChartPayload): IncomeExpenseChartData {
    return {
        labels: payload.labels,
        datasets: [
            {
                label: 'Income',
                data: payload.income,
                backgroundColor: INCOME_COLOR,
                borderRadius: 4,
            },
            {
                label: 'Expenses',
                data: payload.expenses,
                backgroundColor: EXPENSE_COLOR,
                borderRadius: 4,
            },
        ],
    };
}
