import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface CashFlowRow {
    account_id: number;
    code: string;
    name: string;
    amount: number;
}

interface CashFlowRowSection {
    rows: CashFlowRow[];
    total: number;
}

interface OperatingSection {
    net_income: number;
    add_backs: CashFlowRowSection;
    fx_reclass: number;
    working_capital: CashFlowRowSection;
    total: number;
}

interface CashFlowReportData {
    from: string;
    to: string;
    operating: OperatingSection;
    investing: CashFlowRowSection;
    financing: CashFlowRowSection;
    fx_on_cash: number;
    net_change_in_cash: number;
    opening_cash: number;
    closing_cash: number;
    reconciles_to_cash: boolean;
}

interface CashFlowPageProps extends PageProps {
    report: CashFlowReportData;
    canExport: boolean;
}

const INPUT_CLASSES =
    'h-9 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

const FX_RECLASS_NOTE =
    'Foreign-exchange gains/losses are pulled out of operating activities here and shown on their own “Effect of foreign exchange on cash” line below, so a currency swing is never buried inside day-to-day operations.';

function Rows({ rows, currency }: { rows: CashFlowRow[]; currency: string }) {
    return (
        <>
            {rows.map((row) => (
                <tr key={row.account_id} className="border-b border-rule text-ink last:border-b-0">
                    <td className="py-2 pr-3 align-top">{row.name}</td>
                    <td className="py-2 pl-3 align-top">
                        <MoneyText minor={row.amount} currency={currency} size="sm" />
                    </td>
                </tr>
            ))}
        </>
    );
}

function LineItem({
    label,
    amount,
    currency,
    note,
}: {
    label: string;
    amount: number;
    currency: string;
    note?: string;
}) {
    return (
        <tr className="border-b border-rule text-ink last:border-b-0">
            <td className="py-2 pr-3 align-top">
                {note ? (
                    <span title={note} className="cursor-help underline decoration-dotted decoration-ink-700 underline-offset-4">
                        {label}
                    </span>
                ) : (
                    label
                )}
            </td>
            <td className="py-2 pl-3 align-top">
                <MoneyText minor={amount} currency={currency} size="sm" />
            </td>
        </tr>
    );
}

function SectionTotal({ label, amount, currency }: { label: string; amount: number; currency: string }) {
    return (
        <tr className="border-b-2 border-rule font-semibold text-ink">
            <td className="py-2 pr-3">{label}</td>
            <td className="py-2 pl-3">
                <MoneyText minor={amount} currency={currency} size="sm" />
            </td>
        </tr>
    );
}

/**
 * Statement of Cash Flows, indirect method (spec seed §2, Phase 7 Task 4):
 * Operating / Investing / Financing, plus a dedicated "Effect of foreign
 * exchange on cash" line and a visible reconciliation to the actual cash
 * balance. Never shows debit/credit language (PROJECT_MAP §9 rule 8 — this
 * is a statement, not an accountant screen).
 */
export default function CashFlow({ report, canExport }: CashFlowPageProps) {
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

        router.get(route('reports.cash-flow'), { from: nextFrom, to: nextTo }, { preserveState: true, preserveScroll: true, replace: true });
    }

    const exportHref = `${route('reports.cash-flow.export')}?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`;
    const isEmpty =
        report.operating.total === 0 &&
        report.investing.total === 0 &&
        report.financing.total === 0 &&
        report.fx_on_cash === 0 &&
        report.opening_cash === 0 &&
        report.closing_cash === 0;

    return (
        <AppShell title="Cash flow">
            <Head title="Cash flow" />

            <Sheet
                title="Statement of cash flows"
                actions={
                    <>
                        <label htmlFor="cf-from" className="flex items-center gap-2 text-sm text-ink-700">
                            From
                            <input
                                id="cf-from"
                                type="date"
                                value={from}
                                onChange={(event) => reload(event.target.value, to)}
                                className={INPUT_CLASSES}
                            />
                        </label>
                        <label htmlFor="cf-to" className="flex items-center gap-2 text-sm text-ink-700">
                            To
                            <input id="cf-to" type="date" value={to} onChange={(event) => reload(from, event.target.value)} className={INPUT_CLASSES} />
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
                    </>
                }
            >
                {isEmpty ? (
                    <p className="text-sm text-ink-700">No cash activity for this period.</p>
                ) : (
                    <div className="flex flex-col gap-8">
                        <section>
                            <h3 className="pb-2 font-display text-sm font-semibold text-ink">Operating activities</h3>
                            <table className="w-full border-collapse text-left text-sm">
                                <tbody>
                                    <LineItem label="Net income" amount={report.operating.net_income} currency={currency} />
                                    <Rows rows={report.operating.add_backs.rows} currency={currency} />
                                    <LineItem
                                        label="Foreign exchange (gain)/loss"
                                        amount={report.operating.fx_reclass}
                                        currency={currency}
                                        note={FX_RECLASS_NOTE}
                                    />
                                    <Rows rows={report.operating.working_capital.rows} currency={currency} />
                                </tbody>
                                <tfoot>
                                    <SectionTotal label="Net cash from operating activities" amount={report.operating.total} currency={currency} />
                                </tfoot>
                            </table>
                        </section>

                        <section>
                            <h3 className="pb-2 font-display text-sm font-semibold text-ink">Investing activities</h3>
                            <table className="w-full border-collapse text-left text-sm">
                                <tbody>
                                    {report.investing.rows.length === 0 ? (
                                        <tr>
                                            <td className="py-2 text-ink-700" colSpan={2}>
                                                No investing activity.
                                            </td>
                                        </tr>
                                    ) : (
                                        <Rows rows={report.investing.rows} currency={currency} />
                                    )}
                                </tbody>
                                <tfoot>
                                    <SectionTotal label="Net cash from investing activities" amount={report.investing.total} currency={currency} />
                                </tfoot>
                            </table>
                        </section>

                        <section>
                            <h3 className="pb-2 font-display text-sm font-semibold text-ink">Financing activities</h3>
                            <table className="w-full border-collapse text-left text-sm">
                                <tbody>
                                    {report.financing.rows.length === 0 ? (
                                        <tr>
                                            <td className="py-2 text-ink-700" colSpan={2}>
                                                No financing activity.
                                            </td>
                                        </tr>
                                    ) : (
                                        <Rows rows={report.financing.rows} currency={currency} />
                                    )}
                                </tbody>
                                <tfoot>
                                    <SectionTotal label="Net cash from financing activities" amount={report.financing.total} currency={currency} />
                                </tfoot>
                            </table>
                        </section>

                        <section className="flex flex-col gap-3 border-t-2 border-rule pt-4">
                            <div className="flex items-center justify-between gap-4">
                                <span className="text-sm text-ink">Effect of foreign exchange on cash</span>
                                <MoneyText minor={report.fx_on_cash} currency={currency} size="sm" />
                            </div>
                            <div className="flex items-center justify-between gap-4">
                                <span className="font-display text-sm font-semibold text-ink">Net change in cash</span>
                                <MoneyText minor={report.net_change_in_cash} currency={currency} size="md" className="font-semibold" />
                            </div>
                            <div className="flex items-center justify-between gap-4">
                                <span className="text-sm text-ink">Cash at beginning of period</span>
                                <MoneyText minor={report.opening_cash} currency={currency} size="sm" />
                            </div>
                            <div className="flex items-center justify-between gap-4">
                                <span className="font-display text-sm font-semibold text-ink">Cash at end of period</span>
                                <MoneyText minor={report.closing_cash} currency={currency} size="md" className="font-semibold" />
                            </div>
                            <div className="flex items-center justify-between gap-4 rounded-md bg-paper px-3 py-2">
                                <span className="text-sm font-medium text-ink">
                                    {report.reconciles_to_cash ? 'Reconciles to cash' : 'Does not reconcile to cash'}
                                </span>
                                {report.reconciles_to_cash ? (
                                    <span className="text-sm font-semibold text-spark">✓</span>
                                ) : (
                                    <MoneyText
                                        minor={report.closing_cash - (report.opening_cash + report.net_change_in_cash)}
                                        currency={currency}
                                        size="sm"
                                        className="font-semibold text-clay"
                                    />
                                )}
                            </div>
                        </section>
                    </div>
                )}
            </Sheet>
        </AppShell>
    );
}
