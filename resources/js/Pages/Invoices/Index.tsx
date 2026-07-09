import Button from '@/Components/ui/Button';
import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import StatusChip from '@/Components/invoices/StatusChip';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

interface InvoiceRow {
    id: number;
    number: string | null;
    status: string;
    contact_name: string;
    issue_date: string | null;
    due_date: string | null;
    currency_code: string;
    /** Integer minor units. */
    total: number;
    /** Integer minor units. */
    balance: number;
}

interface IndexPageProps extends PageProps {
    invoices: InvoiceRow[];
}

type StatusFilter = 'all' | 'draft' | 'sent' | 'partially_paid' | 'paid' | 'void';

const STATUS_FILTER_OPTIONS: { value: StatusFilter; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'draft', label: 'Draft' },
    { value: 'sent', label: 'Sent' },
    { value: 'partially_paid', label: 'Partially paid' },
    { value: 'paid', label: 'Paid' },
    { value: 'void', label: 'Void' },
];

function formatDate(value: string | null): string {
    if (!value) {
        return '';
    }
    return new Date(`${value}T00:00:00`).toLocaleDateString('en-PK', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

function todayIsoDate(): string {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

/** Only a still-payable invoice (sent/partially_paid) past its due date reads as overdue — never draft, paid, or void. */
function isOverdue(invoice: InvoiceRow, today: string): boolean {
    return (
        (invoice.status === 'sent' || invoice.status === 'partially_paid') &&
        invoice.due_date !== null &&
        invoice.due_date < today
    );
}

function OverdueTag() {
    return (
        <span className="ml-2 inline-block rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-ink">
            Overdue
        </span>
    );
}

export default function Index({ invoices }: IndexPageProps) {
    const { currentCompany } = usePage<PageProps>().props;
    const currency = currentCompany?.base_currency ?? 'USD';
    const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');
    const today = useMemo(() => todayIsoDate(), []);

    const visibleInvoices = useMemo(
        () =>
            statusFilter === 'all'
                ? invoices
                : invoices.filter((invoice) => invoice.status === statusFilter),
        [invoices, statusFilter],
    );

    return (
        <AppShell title="Invoices">
            <Head title="Invoices" />

            <div className="mb-4 flex items-center justify-between gap-4">
                <div
                    role="group"
                    aria-label="Filter by status"
                    className="inline-flex flex-wrap rounded-lg border border-rule bg-paper-raised p-0.5"
                >
                    {STATUS_FILTER_OPTIONS.map((option) => (
                        <button
                            key={option.value}
                            type="button"
                            aria-pressed={statusFilter === option.value}
                            onClick={() => setStatusFilter(option.value)}
                            className={
                                `rounded-md px-3 py-1 text-sm font-medium transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus-visible:ring-2 focus-visible:ring-spark ` +
                                (statusFilter === option.value
                                    ? 'bg-ink text-white'
                                    : 'text-ink-700 hover:bg-paper')
                            }
                        >
                            {option.label}
                        </button>
                    ))}
                </div>
                <Button type="button" onClick={() => router.visit(route('invoices.new'))}>
                    New invoice
                </Button>
            </div>

            <Sheet>
                {visibleInvoices.length === 0 ? (
                    <p className="py-8 text-center text-sm text-ink-700">
                        {invoices.length === 0
                            ? 'No invoices yet.'
                            : 'No invoices match this filter.'}
                    </p>
                ) : (
                    <table className="w-full border-collapse text-left text-sm">
                        <thead>
                            <tr className="border-b border-rule text-xs font-medium uppercase tracking-wide text-ink-700">
                                <th scope="col" className="py-2 pr-3 font-medium">
                                    Number
                                </th>
                                <th scope="col" className="py-2 pr-3 font-medium">
                                    Customer
                                </th>
                                <th scope="col" className="py-2 pr-3 font-medium">
                                    Issue date
                                </th>
                                <th scope="col" className="py-2 pr-3 font-medium">
                                    Due date
                                </th>
                                <th scope="col" className="py-2 pr-3 font-medium">
                                    Status
                                </th>
                                <th scope="col" className="py-2 pl-3 text-right font-medium">
                                    Total
                                </th>
                                <th scope="col" className="py-2 pl-3 text-right font-medium">
                                    Balance
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {visibleInvoices.map((invoice) => {
                                const overdue = isOverdue(invoice, today);

                                return (
                                    <tr
                                        key={invoice.id}
                                        tabIndex={0}
                                        role="button"
                                        onClick={() => router.visit(route('invoices.show', invoice.id))}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter' || event.key === ' ') {
                                                event.preventDefault();
                                                router.visit(route('invoices.show', invoice.id));
                                            }
                                        }}
                                        className="cursor-pointer border-b border-rule text-ink transition duration-[120ms] ease-out motion-reduce:transition-none hover:bg-paper focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-spark last:border-b-0"
                                    >
                                        <td className="py-3 pr-3 align-top font-mono text-xs">
                                            {invoice.number ?? 'Draft'}
                                        </td>
                                        <td className="py-3 pr-3 align-top">{invoice.contact_name}</td>
                                        <td className="py-3 pr-3 align-top text-ink-700">
                                            {formatDate(invoice.issue_date)}
                                        </td>
                                        <td className="py-3 pr-3 align-top text-ink-700">
                                            {formatDate(invoice.due_date)}
                                            {overdue && <OverdueTag />}
                                        </td>
                                        <td className="py-3 pr-3 align-top">
                                            <StatusChip status={invoice.status} />
                                        </td>
                                        <td className="py-3 pl-3 align-top">
                                            <MoneyText
                                                minor={invoice.total}
                                                currency={invoice.currency_code || currency}
                                                size="sm"
                                            />
                                        </td>
                                        <td className="py-3 pl-3 align-top">
                                            <MoneyText
                                                minor={invoice.balance}
                                                currency={invoice.currency_code || currency}
                                                size="sm"
                                            />
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                )}
            </Sheet>
        </AppShell>
    );
}
