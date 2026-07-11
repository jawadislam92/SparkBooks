import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface TaxSummaryReportData {
    from: string;
    to: string;
    collected: number;
    input: number;
    remittances: number;
    adjustments: number;
    net_tax_payable: number;
}

interface TaxSummaryPageProps extends PageProps {
    report: TaxSummaryReportData;
    canExport: boolean;
}

const INPUT_CLASSES =
    'h-9 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

/**
 * Tax Summary (spec §7.11.6): the net movement of 2100 Tax Payable over a
 * period, split collected (output tax on sales) vs. input (tax on
 * purchases) vs. tax remittances (payments to the tax authority) vs. manual
 * adjustments. Semi-accountant screen — the spec itself uses
 * "collected/input" rather than raw debit/credit language, so this page
 * never says "debit" or "credit" anywhere (PROJECT_MAP §9 rule 8), and the
 * note below spells out that the split comes from WHAT posted it (an
 * invoice vs. an expense/bill vs. a remittance vs. a manual entry), never
 * from which ledger column an amount happened to land in.
 */
export default function TaxSummary({ report, canExport }: TaxSummaryPageProps) {
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
            route('reports.tax-summary'),
            { from: nextFrom, to: nextTo },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    const exportHref = `${route('reports.tax-summary.export')}?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`;

    const rows: { label: string; amount: number }[] = [
        { label: 'Collected (output tax)', amount: report.collected },
        { label: 'Input tax', amount: report.input },
        { label: 'Tax remittances', amount: report.remittances },
        { label: 'Adjustments', amount: report.adjustments },
    ];

    return (
        <AppShell title="Tax summary">
            <Head title="Tax summary" />

            <Sheet
                title="Tax summary"
                actions={
                    <div className="flex flex-wrap items-center gap-2 print:hidden">
                        <label htmlFor="tax-summary-from" className="flex items-center gap-2 text-sm text-ink-700">
                            From
                            <input
                                id="tax-summary-from"
                                type="date"
                                value={from}
                                onChange={(event) => reload(event.target.value, to)}
                                className={INPUT_CLASSES}
                            />
                        </label>
                        <label htmlFor="tax-summary-to" className="flex items-center gap-2 text-sm text-ink-700">
                            To
                            <input
                                id="tax-summary-to"
                                type="date"
                                value={to}
                                onChange={(event) => reload(from, event.target.value)}
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
                <div className="mb-4 rounded-md bg-paper px-3 py-2 text-sm text-ink-700">
                    Collected and input are split by what kind of document posted the tax — an invoice (and
                    any void of it) vs. an expense or bill (and any void of it) — never by which ledger column
                    the amount landed in. A tax remittance (a payment to the tax authority) is shown on its
                    own line, separate from ordinary input tax on a purchase.
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full min-w-max border-collapse text-left text-sm">
                        <thead>
                            <tr className="border-b border-rule text-xs font-medium uppercase tracking-wide text-ink-700">
                                <th scope="col" className="py-2 pr-3 font-medium">
                                    Line
                                </th>
                                <th scope="col" className="py-2 pl-3 text-right font-medium">
                                    Amount
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.label} className="border-b border-rule text-ink last:border-b-0">
                                    <td className="py-2 pr-3 align-top">{row.label}</td>
                                    <td className="py-2 pl-3 align-top">
                                        <MoneyText minor={row.amount} currency={currency} size="sm" />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="border-t-2 border-rule font-semibold text-ink">
                                <td className="py-3 pr-3 font-display text-base">Net tax payable</td>
                                <td className="py-3 pl-3">
                                    <MoneyText
                                        minor={report.net_tax_payable}
                                        currency={currency}
                                        size="lg"
                                        className="font-semibold"
                                    />
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </Sheet>
        </AppShell>
    );
}
