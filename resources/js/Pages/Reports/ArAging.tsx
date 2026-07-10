import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface AgingContactRow {
    contact_id: number;
    name: string;
    buckets: Record<string, number>;
    total: number;
}

interface AgingControlAccount {
    account_id: number;
    code: string;
    name: string;
}

interface AgingReportData {
    as_of: string;
    direction: 'ar' | 'ap';
    control_account: AgingControlAccount | null;
    control_balance: number;
    buckets: string[];
    bucket_labels: Record<string, string>;
    contacts: AgingContactRow[];
    totals: Record<string, number>;
    grand_total: number;
    ties_to_control: boolean;
    difference: number;
}

interface ArAgingPageProps extends PageProps {
    report: AgingReportData;
    canExport: boolean;
}

const INPUT_CLASSES =
    'h-9 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

const TIE_OUT_NOTE =
    'Every dollar shown here is drawn from the same ledger balance as the Accounts Receivable control account — this total is always expected to match it exactly.';

/**
 * A/R Aging (spec §7.11): outstanding customer balances bucketed by how many
 * days past each invoice's own due date the report date falls, rather than
 * by ledger date (the ONE report where the bucket comes from the source
 * document, not the ledger — see AgingReport's class docblock). Never shows
 * debit/credit language (PROJECT_MAP §9 rule 8) — "outstanding" and "overdue"
 * only. The control-account tie-out banner at the bottom is the correctness
 * gate made visible: this report's total is provably the same number as the
 * 1100 control account's own balance (AgingReport::buildReceivables()), so a
 * mismatch here would mean a genuine ledger bug, not a display quirk.
 */
export default function ArAging({ report, canExport }: ArAgingPageProps) {
    const { currentCompany } = usePage<PageProps>().props;
    const currency = currentCompany?.base_currency ?? 'USD';
    const [asOf, setAsOf] = useState(report.as_of);

    function reload(nextAsOf: string) {
        setAsOf(nextAsOf);

        if (!nextAsOf) {
            return;
        }

        router.get(
            route('reports.ar-aging'),
            { as_of: nextAsOf },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    const exportHref = `${route('reports.ar-aging.export')}?as_of=${encodeURIComponent(asOf)}`;
    const isEmpty = report.contacts.length === 0;

    return (
        <AppShell title="A/R aging">
            <Head title="A/R aging" />

            <Sheet
                title="A/R aging"
                actions={
                    <div className="flex items-center gap-2 print:hidden">
                        <label htmlFor="ar-aging-as-of" className="flex items-center gap-2 text-sm text-ink-700">
                            As of
                            <input
                                id="ar-aging-as-of"
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
                    <p className="text-sm text-ink-700">No outstanding customer balances as of this date.</p>
                ) : (
                    <div className="flex flex-col gap-4">
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-max border-collapse text-left text-sm">
                                <thead>
                                    <tr className="border-b border-rule text-xs font-medium uppercase tracking-wide text-ink-700">
                                        <th scope="col" className="py-2 pr-3 font-medium">
                                            Customer
                                        </th>
                                        {report.buckets.map((key) => (
                                            <th key={key} scope="col" className="py-2 pl-3 text-right font-medium">
                                                {report.bucket_labels[key]}
                                            </th>
                                        ))}
                                        <th scope="col" className="py-2 pl-3 text-right font-medium">
                                            Total
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {report.contacts.map((row) => (
                                        <tr key={row.contact_id} className="border-b border-rule text-ink last:border-b-0">
                                            <td className="py-2 pr-3 align-top">
                                                <Link
                                                    href={route('contacts.show', row.contact_id)}
                                                    className="text-spark underline decoration-dotted underline-offset-4 hover:no-underline"
                                                >
                                                    {row.name}
                                                </Link>
                                            </td>
                                            {report.buckets.map((key) => (
                                                <td key={key} className="py-2 pl-3 align-top">
                                                    <MoneyText minor={row.buckets[key]} currency={currency} size="sm" />
                                                </td>
                                            ))}
                                            <td className="py-2 pl-3 align-top">
                                                <MoneyText minor={row.total} currency={currency} size="sm" className="font-semibold" />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot>
                                    <tr className="border-t-2 border-rule font-semibold text-ink">
                                        <td className="py-2 pr-3">Total</td>
                                        {report.buckets.map((key) => (
                                            <td key={key} className="py-2 pl-3">
                                                <MoneyText minor={report.totals[key]} currency={currency} size="sm" />
                                            </td>
                                        ))}
                                        <td className="py-2 pl-3">
                                            <MoneyText minor={report.grand_total} currency={currency} size="sm" />
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <div className="flex items-center justify-between gap-4 rounded-md bg-paper px-3 py-2 print:hidden">
                            <span title={TIE_OUT_NOTE} className="cursor-help text-sm font-medium text-ink underline decoration-dotted underline-offset-4">
                                {report.ties_to_control ? 'Ties to control account' : 'Does not tie to control account'}
                            </span>
                            {report.ties_to_control ? (
                                <span className="text-sm font-semibold text-spark">✓</span>
                            ) : (
                                <MoneyText minor={report.difference} currency={currency} size="sm" className="font-semibold text-clay" />
                            )}
                        </div>
                    </div>
                )}
            </Sheet>
        </AppShell>
    );
}
