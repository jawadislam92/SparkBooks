import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface BalanceSheetRow {
    account_id: number;
    code: string;
    name: string;
    amount: number;
}

interface BalanceSheetSection {
    rows: BalanceSheetRow[];
    total: number;
}

interface BalanceSheetEquitySection {
    rows: BalanceSheetRow[];
    retained_earnings: number;
    current_year_earnings: number;
    total: number;
}

interface RetainedEarningsDetail {
    posted_balance: number;
    completed_fiscal_years_net_income: number;
    completed_fiscal_years: Array<{ start: string; end: string; net_income: number }>;
}

interface BalanceSheetReportData {
    as_of: string;
    assets: BalanceSheetSection;
    liabilities: BalanceSheetSection;
    equity: BalanceSheetEquitySection;
    total_assets: number;
    total_liabilities_and_equity: number;
    is_balanced: boolean;
    difference: number;
    retained_earnings_detail: RetainedEarningsDetail;
    current_fiscal_year: { start: string; end: string };
}

interface BalanceSheetPageProps extends PageProps {
    report: BalanceSheetReportData;
    canExport: boolean;
}

const INPUT_CLASSES =
    'h-9 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

const RETAINED_EARNINGS_NOTE =
    'Retained Earnings here folds every completed fiscal year’s net income into account 3200’s posted balance, and shows the current fiscal year’s earnings on its own line below. The Trial Balance shows 3200 at its raw posted balance instead, alongside the still-open income and expense accounts — so the two reports will show different Retained Earnings figures by design.';

function SectionRows({ rows, currency }: { rows: BalanceSheetRow[]; currency: string }) {
    return (
        <>
            {rows.map((row) => (
                <tr key={row.account_id} className="border-b border-rule text-ink last:border-b-0">
                    <td className="py-2 pr-3 align-top font-mono text-xs text-ink-700">{row.code}</td>
                    <td className="py-2 pr-3 align-top">{row.name}</td>
                    <td className="py-2 pl-3 align-top">
                        <MoneyText minor={row.amount} currency={currency} size="sm" />
                    </td>
                </tr>
            ))}
        </>
    );
}

function SectionTotal({ label, amount, currency }: { label: string; amount: number; currency: string }) {
    return (
        <tr className="border-b-2 border-rule font-semibold text-ink">
            <td className="py-2 pr-3" colSpan={2}>
                {label}
            </td>
            <td className="py-2 pl-3">
                <MoneyText minor={amount} currency={currency} size="sm" />
            </td>
        </tr>
    );
}

/**
 * Assets / Liabilities / Equity at a chosen as-of date (spec §7.11.2). The
 * Retained Earnings line folds every COMPLETED fiscal year's net income into
 * account 3200's posted balance; Current Year Earnings is the current
 * (incomplete) fiscal year's net income, shown as its own line — never
 * folded into Retained Earnings. Never shows debit/credit language
 * (PROJECT_MAP §9 rule 8 — this is a non-accountant screen, unlike Trial
 * Balance): the balance check below reads "Assets" vs "Liabilities +
 * Equity", not "debits" vs "credits".
 */
export default function BalanceSheet({ report, canExport }: BalanceSheetPageProps) {
    const { currentCompany } = usePage<PageProps>().props;
    const currency = currentCompany?.base_currency ?? 'USD';
    const [asOf, setAsOf] = useState(report.as_of);

    function reload(nextAsOf: string) {
        setAsOf(nextAsOf);

        if (!nextAsOf) {
            return;
        }

        router.get(
            route('reports.balance-sheet'),
            { as_of: nextAsOf },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    const exportHref = `${route('reports.balance-sheet.export')}?as_of=${encodeURIComponent(asOf)}`;
    const isEmpty =
        report.assets.rows.length === 0 &&
        report.liabilities.rows.length === 0 &&
        report.equity.rows.length === 0 &&
        report.equity.retained_earnings === 0 &&
        report.equity.current_year_earnings === 0;

    return (
        <AppShell title="Balance sheet">
            <Head title="Balance sheet" />

            <Sheet
                title="Balance sheet"
                actions={
                    <div className="flex items-center gap-2 print:hidden">
                        <label htmlFor="balance-sheet-as-of" className="flex items-center gap-2 text-sm text-ink-700">
                            As of
                            <input
                                id="balance-sheet-as-of"
                                type="date"
                                value={asOf}
                                onChange={(event) => reload(event.target.value)}
                                className={INPUT_CLASSES}
                            />
                        </label>
                        <button
                            type="button"
                            onClick={() => window.print()}
                            className="inline-flex h-8 items-center justify-center gap-2 rounded-lg px-3 font-sans text-xs font-semibold text-ink-700 transition duration-[120ms] ease-out hover:bg-paper focus:outline-none focus-visible:ring-2 focus-visible:ring-spark focus-visible:ring-offset-2 focus-visible:ring-offset-paper-raised motion-reduce:transition-none"
                        >
                            Print
                        </button>
                        {canExport && (
                            <a
                                href={exportHref}
                                className="inline-flex h-8 items-center justify-center gap-2 rounded-lg px-3 font-sans text-xs font-semibold text-ink-700 transition duration-[120ms] ease-out hover:bg-paper focus:outline-none focus-visible:ring-2 focus-visible:ring-spark focus-visible:ring-offset-2 focus-visible:ring-offset-paper-raised motion-reduce:transition-none"
                            >
                                Export CSV
                            </a>
                        )}
                    </div>
                }
            >
                {isEmpty ? (
                    <p className="text-sm text-ink-700">No activity as of this date.</p>
                ) : (
                    <div className="flex flex-col gap-8">
                        <section>
                            <h3 className="pb-2 font-display text-sm font-semibold text-ink">Assets</h3>
                            <table className="w-full border-collapse text-left text-sm">
                                <tbody>
                                    <SectionRows rows={report.assets.rows} currency={currency} />
                                </tbody>
                                <tfoot>
                                    <SectionTotal label="Total Assets" amount={report.assets.total} currency={currency} />
                                </tfoot>
                            </table>
                        </section>

                        <section>
                            <h3 className="pb-2 font-display text-sm font-semibold text-ink">Liabilities</h3>
                            <table className="w-full border-collapse text-left text-sm">
                                <tbody>
                                    <SectionRows rows={report.liabilities.rows} currency={currency} />
                                </tbody>
                                <tfoot>
                                    <SectionTotal label="Total Liabilities" amount={report.liabilities.total} currency={currency} />
                                </tfoot>
                            </table>
                        </section>

                        <section>
                            <h3 className="pb-2 font-display text-sm font-semibold text-ink">Equity</h3>
                            <table className="w-full border-collapse text-left text-sm">
                                <tbody>
                                    <SectionRows rows={report.equity.rows} currency={currency} />
                                    <tr className="border-b border-rule text-ink">
                                        <td className="py-2 pr-3 align-top" colSpan={2}>
                                            <span
                                                title={RETAINED_EARNINGS_NOTE}
                                                className="cursor-help underline decoration-dotted decoration-ink-700 underline-offset-4"
                                            >
                                                Retained Earnings
                                            </span>
                                        </td>
                                        <td className="py-2 pl-3 align-top">
                                            <MoneyText minor={report.equity.retained_earnings} currency={currency} size="sm" />
                                        </td>
                                    </tr>
                                    <tr className="border-b border-rule text-ink">
                                        <td className="py-2 pr-3 align-top" colSpan={2}>
                                            Current Year Earnings
                                        </td>
                                        <td className="py-2 pl-3 align-top">
                                            <MoneyText minor={report.equity.current_year_earnings} currency={currency} size="sm" />
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <SectionTotal label="Total Equity" amount={report.equity.total} currency={currency} />
                                </tfoot>
                            </table>
                            <p className="pt-2 text-xs text-ink-700">{RETAINED_EARNINGS_NOTE}</p>
                        </section>

                        <section className="flex flex-col gap-3 border-t-2 border-rule pt-4">
                            <div className="flex items-center justify-between gap-4">
                                <span className="font-display text-sm font-semibold text-ink">Total Assets</span>
                                <MoneyText minor={report.total_assets} currency={currency} size="md" className="font-semibold" />
                            </div>
                            <div className="flex items-center justify-between gap-4">
                                <span className="font-display text-sm font-semibold text-ink">
                                    Total Liabilities &amp; Equity
                                </span>
                                <MoneyText
                                    minor={report.total_liabilities_and_equity}
                                    currency={currency}
                                    size="md"
                                    className="font-semibold"
                                />
                            </div>
                            <div className="flex items-center justify-between gap-4 rounded-md bg-paper px-3 py-2">
                                <span className="text-sm font-medium text-ink">
                                    {report.is_balanced ? 'Balanced' : 'Out of balance'}
                                </span>
                                {!report.is_balanced && (
                                    <MoneyText
                                        minor={report.difference}
                                        currency={currency}
                                        size="sm"
                                        className="font-semibold text-clay"
                                    />
                                )}
                                {report.is_balanced && <span className="text-sm font-semibold text-spark">✓</span>}
                            </div>
                        </section>
                    </div>
                )}
            </Sheet>
        </AppShell>
    );
}
