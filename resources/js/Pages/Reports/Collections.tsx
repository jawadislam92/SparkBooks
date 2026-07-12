import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface CollectionsDocument {
    doc_id: number;
    reference: string;
    due_date: string;
    days_overdue: number;
    outstanding: number;
}

interface CollectionsContactRow {
    contact_id: number;
    name: string;
    email: string | null;
    phone: string | null;
    documents: CollectionsDocument[];
    total: number;
}

interface CollectionsData {
    as_of: string;
    contacts: CollectionsContactRow[];
    grand_total: number;
}

interface CollectionsPageProps extends PageProps {
    report: CollectionsData;
    canExport: boolean;
}

const INPUT_CLASSES =
    'h-9 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

/**
 * Collections report (Phase 7 Task 6): overdue AR grouped by customer, with
 * days-overdue and contact details for chasing
 * (ContactLedgerReport::collections).
 */
export default function Collections({ report, canExport }: CollectionsPageProps) {
    const { currentCompany } = usePage<PageProps>().props;
    const currency = currentCompany?.base_currency ?? 'USD';
    const [asOf, setAsOf] = useState(report.as_of);

    function reload(nextAsOf: string) {
        setAsOf(nextAsOf);
        if (!nextAsOf) {
            return;
        }
        router.get(route('reports.collections'), { as_of: nextAsOf }, { preserveState: true, preserveScroll: true, replace: true });
    }

    const exportHref = `${route('reports.collections.export')}?as_of=${encodeURIComponent(asOf)}`;
    const isEmpty = report.contacts.length === 0;

    return (
        <AppShell title="Collections">
            <Head title="Collections" />

            <Sheet
                title="Collections"
                actions={
                    <div className="flex items-center gap-2 print:hidden">
                        <label htmlFor="col-as-of" className="flex items-center gap-2 text-sm text-ink-700">
                            As of
                            <input
                                id="col-as-of"
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
                    <p className="text-sm text-ink-700">No overdue customer balances as of this date.</p>
                ) : (
                    <div className="flex flex-col gap-6">
                        {report.contacts.map((contact) => (
                            <div key={contact.contact_id} className="flex flex-col gap-2">
                                <div className="flex items-baseline justify-between gap-4">
                                    <h3 className="font-display text-sm font-semibold text-ink">{contact.name}</h3>
                                    <span className="text-xs text-ink-700">
                                        {contact.email ?? '—'}{contact.phone ? ` · ${contact.phone}` : ''}
                                    </span>
                                </div>
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-max border-collapse text-left text-sm">
                                        <thead>
                                            <tr className="border-b border-rule text-xs font-medium uppercase tracking-wide text-ink-700">
                                                <th scope="col" className="py-2 pr-3 font-medium">Invoice</th>
                                                <th scope="col" className="py-2 pr-3 font-medium">Due date</th>
                                                <th scope="col" className="py-2 pr-3 text-right font-medium">Days overdue</th>
                                                <th scope="col" className="py-2 pl-3 text-right font-medium">Outstanding</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {contact.documents.map((doc) => (
                                                <tr key={doc.doc_id} className="border-b border-rule text-ink last:border-b-0">
                                                    <td className="py-2 pr-3 align-top text-ink-700">{doc.reference}</td>
                                                    <td className="py-2 pr-3 align-top text-ink-700">{doc.due_date}</td>
                                                    <td className="py-2 pr-3 align-top text-right text-clay">{doc.days_overdue}</td>
                                                    <td className="py-2 pl-3 align-top">
                                                        <MoneyText minor={doc.outstanding} currency={currency} size="sm" />
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                        <tfoot>
                                            <tr className="border-t-2 border-rule font-semibold text-ink">
                                                <td className="py-2 pr-3" colSpan={3}>Subtotal</td>
                                                <td className="py-2 pl-3">
                                                    <MoneyText minor={contact.total} currency={currency} size="sm" />
                                                </td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        ))}

                        <div className="flex items-center justify-between gap-4 rounded-md bg-paper px-3 py-2 font-semibold text-ink print:hidden">
                            <span>Total overdue</span>
                            <MoneyText minor={report.grand_total} currency={currency} size="sm" />
                        </div>
                    </div>
                )}
            </Sheet>
        </AppShell>
    );
}
