import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface OpenInvoiceRow {
    contact_id: number;
    contact_name: string;
    doc_id: number;
    reference: string;
    date: string | null;
    due_date: string | null;
    currency_code: string | null;
    status: string;
    original: number;
    outstanding: number;
}

interface OpenInvoicesData {
    as_of: string;
    control_balance: number;
    documents: OpenInvoiceRow[];
    total_outstanding: number;
    ties_to_control: boolean;
    difference: number;
}

interface OpenInvoicesPageProps extends PageProps {
    report: OpenInvoicesData;
    canExport: boolean;
}

const INPUT_CLASSES =
    'h-9 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

/**
 * Open Invoices (Phase 7 Task 6): every unpaid/partly-paid invoice with its
 * original + outstanding (base-correct) amount. Total ties to the 1100 AR
 * control account (ContactLedgerReport::openInvoices).
 */
export default function OpenInvoices({ report, canExport }: OpenInvoicesPageProps) {
    const { currentCompany } = usePage<PageProps>().props;
    const currency = currentCompany?.base_currency ?? 'USD';
    const [asOf, setAsOf] = useState(report.as_of);

    function reload(nextAsOf: string) {
        setAsOf(nextAsOf);
        if (!nextAsOf) {
            return;
        }
        router.get(route('reports.open-invoices'), { as_of: nextAsOf }, { preserveState: true, preserveScroll: true, replace: true });
    }

    const exportHref = `${route('reports.open-invoices.export')}?as_of=${encodeURIComponent(asOf)}`;
    const isEmpty = report.documents.length === 0;

    return (
        <AppShell title="Open invoices">
            <Head title="Open invoices" />

            <Sheet
                title="Open invoices"
                actions={
                    <div className="flex items-center gap-2 print:hidden">
                        <label htmlFor="oi-as-of" className="flex items-center gap-2 text-sm text-ink-700">
                            As of
                            <input
                                id="oi-as-of"
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
                    <p className="text-sm text-ink-700">No open invoices as of this date.</p>
                ) : (
                    <div className="flex flex-col gap-4">
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-max border-collapse text-left text-sm">
                                <thead>
                                    <tr className="border-b border-rule text-xs font-medium uppercase tracking-wide text-ink-700">
                                        <th scope="col" className="py-2 pr-3 font-medium">Customer</th>
                                        <th scope="col" className="py-2 pr-3 font-medium">Invoice</th>
                                        <th scope="col" className="py-2 pr-3 font-medium">Date</th>
                                        <th scope="col" className="py-2 pr-3 font-medium">Due date</th>
                                        <th scope="col" className="py-2 pl-3 text-right font-medium">Original</th>
                                        <th scope="col" className="py-2 pl-3 text-right font-medium">Outstanding</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {report.documents.map((row) => (
                                        <tr key={row.doc_id} className="border-b border-rule text-ink last:border-b-0">
                                            <td className="py-2 pr-3 align-top">{row.contact_name}</td>
                                            <td className="py-2 pr-3 align-top text-ink-700">
                                                {row.reference}
                                                {row.currency_code && <span className="ml-1.5 text-xs text-ink-700">({row.currency_code})</span>}
                                            </td>
                                            <td className="py-2 pr-3 align-top text-ink-700">{row.date}</td>
                                            <td className="py-2 pr-3 align-top text-ink-700">{row.due_date ?? '—'}</td>
                                            <td className="py-2 pl-3 align-top">
                                                <MoneyText minor={row.original} currency={currency} size="sm" />
                                            </td>
                                            <td className="py-2 pl-3 align-top">
                                                <MoneyText minor={row.outstanding} currency={currency} size="sm" className="font-semibold" />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot>
                                    <tr className="border-t-2 border-rule font-semibold text-ink">
                                        <td className="py-2 pr-3" colSpan={5}>Total outstanding</td>
                                        <td className="py-2 pl-3">
                                            <MoneyText minor={report.total_outstanding} currency={currency} size="sm" />
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <div className="flex items-center justify-between gap-4 rounded-md bg-paper px-3 py-2 print:hidden">
                            <span className="text-sm font-medium text-ink">
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
