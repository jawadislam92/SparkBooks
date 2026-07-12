import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface DetailTransaction {
    date: string;
    type: string;
    reference: string;
    doc_id: number;
    amount: number;
    running_balance: number;
}

interface VendorBalanceDetailData {
    as_of: string;
    contact: { id: number; name: string } | null;
    transactions: DetailTransaction[];
    balance: number;
}

interface ContactOption {
    id: number;
    name: string;
}

interface VendorBalanceDetailPageProps extends PageProps {
    report: VendorBalanceDetailData | null;
    contacts: ContactOption[];
    asOf: string;
    canExport: boolean;
}

const INPUT_CLASSES =
    'h-9 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

function typeLabel(type: string): string {
    return type.charAt(0).toUpperCase() + type.slice(1);
}

/**
 * Vendor Balance Detail — the AP-side mirror of CustomerBalanceDetail: one
 * vendor's full ledger card, running balance ending at their own summary
 * balance.
 */
export default function VendorBalanceDetail({ report, contacts, asOf: initialAsOf, canExport }: VendorBalanceDetailPageProps) {
    const { currentCompany } = usePage<PageProps>().props;
    const currency = currentCompany?.base_currency ?? 'USD';
    const [contactId, setContactId] = useState(report ? String(report.contact?.id ?? '') : '');
    const [asOf, setAsOf] = useState(report?.as_of ?? initialAsOf);

    function reload(next: { contact_id?: string; as_of?: string }) {
        const nextContactId = next.contact_id ?? contactId;
        const nextAsOf = next.as_of ?? asOf;

        setContactId(nextContactId);
        setAsOf(nextAsOf);

        const query: Record<string, string> = { as_of: nextAsOf };
        if (nextContactId) {
            query.contact_id = nextContactId;
        }

        router.get(route('reports.vendor-balance-detail'), query, { preserveState: true, preserveScroll: true, replace: true });
    }

    const exportHref = `${route('reports.vendor-balance-detail.export')}?contact_id=${contactId}&as_of=${encodeURIComponent(asOf)}`;

    return (
        <AppShell title="Vendor balance detail">
            <Head title="Vendor balance detail" />

            <Sheet
                title="Vendor balance detail"
                actions={
                    <div className="flex flex-wrap items-center gap-2 print:hidden">
                        <label htmlFor="vbd-contact" className="flex items-center gap-2 text-sm text-ink-700">
                            Vendor
                            <select
                                id="vbd-contact"
                                value={contactId}
                                onChange={(event) => reload({ contact_id: event.target.value })}
                                className={INPUT_CLASSES}
                            >
                                <option value="">Select a vendor…</option>
                                {contacts.map((option) => (
                                    <option key={option.id} value={option.id}>
                                        {option.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label htmlFor="vbd-as-of" className="flex items-center gap-2 text-sm text-ink-700">
                            As of
                            <input
                                id="vbd-as-of"
                                type="date"
                                value={asOf}
                                onChange={(event) => reload({ as_of: event.target.value })}
                                className={INPUT_CLASSES}
                            />
                        </label>
                        {report && (
                            <button
                                type="button"
                                onClick={() => window.print()}
                                className="inline-flex h-8 items-center justify-center gap-2 rounded-lg px-3 font-sans text-xs font-semibold text-ink-700 transition duration-[120ms] ease-out hover:bg-paper focus:outline-none focus-visible:ring-2 focus-visible:ring-spark focus-visible:ring-offset-2 focus-visible:ring-offset-paper-raised motion-reduce:transition-none"
                            >
                                Print
                            </button>
                        )}
                        {report && canExport && (
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
                {!report ? (
                    <p className="text-sm text-ink-700">Select a vendor above to see their transaction history.</p>
                ) : (
                    <div className="flex flex-col gap-4">
                        <h3 className="font-display text-sm font-semibold text-ink">{report.contact?.name}</h3>
                        {report.transactions.length === 0 ? (
                            <p className="text-sm text-ink-700">No AP-affecting transactions for this vendor.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-max border-collapse text-left text-sm">
                                    <thead>
                                        <tr className="border-b border-rule text-xs font-medium uppercase tracking-wide text-ink-700">
                                            <th scope="col" className="py-2 pr-3 font-medium">Date</th>
                                            <th scope="col" className="py-2 pr-3 font-medium">Type</th>
                                            <th scope="col" className="py-2 pr-3 font-medium">Reference</th>
                                            <th scope="col" className="py-2 pl-3 text-right font-medium">Amount</th>
                                            <th scope="col" className="py-2 pl-3 text-right font-medium">Balance</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {report.transactions.map((row, index) => (
                                            <tr key={index} className="border-b border-rule text-ink last:border-b-0">
                                                <td className="py-2 pr-3 align-top">{row.date}</td>
                                                <td className="py-2 pr-3 align-top text-ink-700">{typeLabel(row.type)}</td>
                                                <td className="py-2 pr-3 align-top text-ink-700">{row.reference}</td>
                                                <td className="py-2 pl-3 align-top">
                                                    <MoneyText minor={row.amount} currency={currency} size="sm" />
                                                </td>
                                                <td className="py-2 pl-3 align-top">
                                                    <MoneyText minor={row.running_balance} currency={currency} size="sm" className="font-semibold" />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                    <tfoot>
                                        <tr className="border-t-2 border-rule font-semibold text-ink">
                                            <td className="py-2 pr-3" colSpan={4}>Closing balance</td>
                                            <td className="py-2 pl-3">
                                                <MoneyText minor={report.balance} currency={currency} size="sm" />
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        )}
                    </div>
                )}
            </Sheet>
        </AppShell>
    );
}
