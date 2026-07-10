import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface ProfitAndLossRow {
    account_id: number;
    code: string;
    name: string;
    amount: number;
    months: number[];
}

interface ProfitAndLossSection {
    rows: ProfitAndLossRow[];
    total: number;
    total_by_month: number[];
}

interface ProfitAndLossMonth {
    label: string;
    start: string;
    end: string;
}

interface ProfitAndLossReportData {
    from: string;
    to: string;
    months: ProfitAndLossMonth[];
    income: ProfitAndLossSection;
    expenses: ProfitAndLossSection;
    net_profit: number;
    net_profit_by_month: number[];
}

interface ProfitAndLossPageProps extends PageProps {
    report: ProfitAndLossReportData;
    canExport: boolean;
}

const INPUT_CLASSES =
    'h-9 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

/** Positive amounts read as spark (healthy), negative as clay (a loss) — used only for the Net Profit line. */
function toneClass(amount: number): string {
    return amount < 0 ? 'text-clay' : 'text-spark';
}

function SectionRows({
    section,
    currency,
    monthCount,
}: {
    section: ProfitAndLossSection;
    currency: string;
    monthCount: number;
}) {
    return (
        <>
            {section.rows.map((row) => (
                <tr key={row.account_id} className="border-b border-rule text-ink last:border-b-0">
                    <td className="py-2 pr-3 align-top font-mono text-xs text-ink-700">
                        {row.code}
                    </td>
                    <td className="py-2 pr-3 align-top">{row.name}</td>
                    {row.months.map((amount, index) => (
                        <td key={index} className="py-2 pl-3 align-top">
                            <MoneyText minor={amount} currency={currency} size="sm" />
                        </td>
                    ))}
                    <td className="py-2 pl-3 align-top">
                        <MoneyText minor={row.amount} currency={currency} size="sm" />
                    </td>
                </tr>
            ))}
            <tr className="border-b-2 border-rule font-semibold text-ink">
                <td className="py-2 pr-3" colSpan={2}>
                    Total
                </td>
                {Array.from({ length: monthCount }, (_, index) => (
                    <td key={index} className="py-2 pl-3">
                        <MoneyText minor={section.total_by_month[index]} currency={currency} size="sm" />
                    </td>
                ))}
                <td className="py-2 pl-3">
                    <MoneyText minor={section.total} currency={currency} size="sm" />
                </td>
            </tr>
        </>
    );
}

/**
 * The income statement: income accounts (grouped, each account's period
 * movement) less expense accounts, with Net Profit emphasized at the bottom.
 * Never shows debit/credit language (PROJECT_MAP §9 rule 8 — this is a
 * non-accountant screen, unlike Trial Balance). Monthly-comparison columns
 * are always on: every calendar month in the selected range gets its own
 * column, plus a Total column, so the same table works for a single month or
 * a full fiscal year.
 */
export default function ProfitAndLoss({ report, canExport }: ProfitAndLossPageProps) {
    const { currentCompany } = usePage<PageProps>().props;
    const currency = currentCompany?.base_currency ?? 'USD';
    const [from, setFrom] = useState(report.from);
    const [to, setTo] = useState(report.to);

    function reload(nextFrom: string, nextTo: string) {
        setFrom(nextFrom);
        setTo(nextTo);

        if (!nextFrom || !nextTo) {
            return;
        }

        router.get(
            route('reports.profit-and-loss'),
            { from: nextFrom, to: nextTo },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    const exportHref = `${route('reports.profit-and-loss.export')}?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`;
    const monthCount = report.months.length;
    const isEmpty = report.income.rows.length === 0 && report.expenses.rows.length === 0;

    return (
        <AppShell title="Profit & loss">
            <Head title="Profit & loss" />

            <Sheet
                title="Profit & loss"
                actions={
                    <>
                        <label htmlFor="pnl-from" className="flex items-center gap-2 text-sm text-ink-700">
                            From
                            <input
                                id="pnl-from"
                                type="date"
                                value={from}
                                onChange={(event) => reload(event.target.value, to)}
                                className={INPUT_CLASSES}
                            />
                        </label>
                        <label htmlFor="pnl-to" className="flex items-center gap-2 text-sm text-ink-700">
                            To
                            <input
                                id="pnl-to"
                                type="date"
                                value={to}
                                onChange={(event) => reload(from, event.target.value)}
                                className={INPUT_CLASSES}
                            />
                        </label>
                        {canExport && (
                            <a
                                href={exportHref}
                                className="inline-flex h-8 items-center justify-center gap-2 rounded-lg px-3 font-sans text-xs font-semibold text-ink-700 transition duration-[120ms] ease-out hover:bg-paper focus:outline-none focus-visible:ring-2 focus-visible:ring-spark focus-visible:ring-offset-2 focus-visible:ring-offset-paper-raised motion-reduce:transition-none"
                            >
                                Export CSV
                            </a>
                        )}
                    </>
                }
            >
                {isEmpty ? (
                    <p className="text-sm text-ink-700">No activity for this period.</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-max border-collapse text-left text-sm">
                            <thead>
                                <tr className="border-b border-rule text-xs font-medium uppercase tracking-wide text-ink-700">
                                    <th scope="col" className="py-2 pr-3 font-medium">
                                        Code
                                    </th>
                                    <th scope="col" className="py-2 pr-3 font-medium">
                                        Account
                                    </th>
                                    {report.months.map((month) => (
                                        <th key={month.label} scope="col" className="py-2 pl-3 text-right font-medium">
                                            {month.label}
                                        </th>
                                    ))}
                                    <th scope="col" className="py-2 pl-3 text-right font-medium">
                                        Total
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colSpan={monthCount + 3} className="pb-1 pt-4 font-display text-sm font-semibold text-ink">
                                        Income
                                    </td>
                                </tr>
                                <SectionRows section={report.income} currency={currency} monthCount={monthCount} />

                                <tr>
                                    <td colSpan={monthCount + 3} className="pb-1 pt-4 font-display text-sm font-semibold text-ink">
                                        Expenses
                                    </td>
                                </tr>
                                <SectionRows section={report.expenses} currency={currency} monthCount={monthCount} />
                            </tbody>
                            <tfoot>
                                <tr className="border-t-2 border-rule">
                                    <td className="pt-4 pr-3 align-top font-display text-base font-semibold text-ink" colSpan={2}>
                                        Net profit
                                    </td>
                                    {report.net_profit_by_month.map((amount, index) => (
                                        <td key={index} className="pt-4 pl-3 align-top">
                                            <MoneyText
                                                minor={amount}
                                                currency={currency}
                                                size="sm"
                                                className={`font-semibold ${toneClass(amount)}`}
                                            />
                                        </td>
                                    ))}
                                    <td className="pt-4 pl-3 align-top">
                                        <MoneyText
                                            minor={report.net_profit}
                                            currency={currency}
                                            size="lg"
                                            className={`font-semibold ${toneClass(report.net_profit)}`}
                                        />
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                )}
            </Sheet>
        </AppShell>
    );
}
