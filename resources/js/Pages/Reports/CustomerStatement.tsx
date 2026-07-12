import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface StatementTransaction {
    date: string;
    type: string;
    reference: string;
    doc_id: number;
    amount: number;
    running_balance: number;
}

interface StatementContact {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
}

interface CustomerStatementData {
    from: string;
    to: string;
    contact: StatementContact | null;
    opening_balance: number;
    transactions: StatementTransaction[];
    closing_balance: number;
}

interface ContactOption {
    id: number;
    name: string;
}

interface CustomerStatementPageProps extends PageProps {
    report: CustomerStatementData | null;
    contacts: ContactOption[];
    from: string;
    to: string;
}

const INPUT_CLASSES =
    'h-9 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

function typeLabel(type: string): string {
    return type.charAt(0).toUpperCase() + type.slice(1);
}

/**
 * Customer Statement (Phase 7 Task 6) — the client-facing artifact: opening
 * balance, every AR transaction in the period, running balance, closing
 * balance (ties to the customer's real AR position — see
 * ContactLedgerReport::customerStatement). Printable in-app and downloadable
 * as a branded PDF (mirrors the invoice PDF pattern).
 */
export default function CustomerStatement({ report, contacts, from: initialFrom, to: initialTo }: CustomerStatementPageProps) {
    const { currentCompany } = usePage<PageProps>().props;
    const currency = currentCompany?.base_currency ?? 'USD';
    const [contactId, setContactId] = useState(report ? String(report.contact?.id ?? '') : '');
    const [from, setFrom] = useState(report?.from ?? initialFrom);
    const [to, setTo] = useState(report?.to ?? initialTo);

    function reload(next: { contact_id?: string; from?: string; to?: string }) {
        const nextContactId = next.contact_id ?? contactId;
        const nextFrom = next.from ?? from;
        const nextTo = next.to ?? to;

        setContactId(nextContactId);
        setFrom(nextFrom);
        setTo(nextTo);

        if (!nextFrom || !nextTo) {
            return;
        }

        const query: Record<string, string> = { from: nextFrom, to: nextTo };
        if (nextContactId) {
            query.contact_id = nextContactId;
        }

        router.get(route('reports.customer-statement'), query, { preserveState: true, preserveScroll: true, replace: true });
    }

    const pdfQuery = new URLSearchParams({ contact_id: contactId, from, to });
    const pdfHref = `${route('reports.customer-statement.pdf')}?${pdfQuery.toString()}`;

    return (
        <AppShell title="Customer statement">
            <Head title="Customer statement" />

            <Sheet
                title="Customer statement"
                actions={
                    <div className="flex flex-wrap items-center gap-2 print:hidden">
                        <label htmlFor="cs-contact" className="flex items-center gap-2 text-sm text-ink-700">
                            Customer
                            <select
                                id="cs-contact"
                                value={contactId}
                                onChange={(event) => reload({ contact_id: event.target.value })}
                                className={INPUT_CLASSES}
                            >
                                <option value="">Select a customer…</option>
                                {contacts.map((option) => (
                                    <option key={option.id} value={option.id}>
                                        {option.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label htmlFor="cs-from" className="flex items-center gap-2 text-sm text-ink-700">
                            From
                            <input id="cs-from" type="date" value={from} onChange={(event) => reload({ from: event.target.value })} className={INPUT_CLASSES} />
                        </label>
                        <label htmlFor="cs-to" className="flex items-center gap-2 text-sm text-ink-700">
                            To
                            <input id="cs-to" type="date" value={to} onChange={(event) => reload({ to: event.target.value })} className={INPUT_CLASSES} />
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
                        {report && (
                            <a
                                href={pdfHref}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex h-8 items-center justify-center gap-2 rounded-lg bg-ink px-3 font-sans text-xs font-semibold text-paper transition duration-[120ms] ease-out hover:bg-ink/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-spark focus-visible:ring-offset-2 focus-visible:ring-offset-paper-raised motion-reduce:transition-none"
                            >
                                Download PDF
                            </a>
                        )}
                    </div>
                }
            >
                {!report ? (
                    <p className="text-sm text-ink-700">Select a customer above to see their statement.</p>
                ) : (
                    <div className="flex flex-col gap-4">
                        <div className="flex items-baseline justify-between gap-4">
                            <h3 className="font-display text-sm font-semibold text-ink">{report.contact?.name}</h3>
                            <span className="text-xs text-ink-700">
                                {report.contact?.email ?? '—'}{report.contact?.phone ? ` · ${report.contact.phone}` : ''}
                            </span>
                        </div>
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
                                    <tr className="border-b border-rule text-ink-700">
                                        <td className="py-2 pr-3 italic" colSpan={4}>Opening balance</td>
                                        <td className="py-2 pl-3">
                                            <MoneyText minor={report.opening_balance} currency={currency} size="sm" />
                                        </td>
                                    </tr>
                                    {report.transactions.map((row, index) => (
                                        <tr key={index} className="border-b border-rule text-ink last:border-b-0">
                                            <td className="py-2 pr-3 align-top">{row.date}</td>
                                            <td className="py-2 pr-3 align-top text-ink-700">{typeLabel(row.type)}</td>
                                            <td className="py-2 pr-3 align-top text-ink-700">{row.reference}</td>
                                            <td className="py-2 pl-3 align-top">
                                                <MoneyText minor={row.amount} currency={currency} size="sm" />
                                            </td>
                                            <td className="py-2 pl-3 align-top">
                                                <MoneyText minor={row.running_balance} currency={currency} size="sm" />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot>
                                    <tr className="border-t-2 border-rule font-semibold text-ink">
                                        <td className="py-2 pr-3" colSpan={4}>Closing balance</td>
                                        <td className="py-2 pl-3">
                                            <MoneyText minor={report.closing_balance} currency={currency} size="sm" />
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                )}
            </Sheet>
        </AppShell>
    );
}
