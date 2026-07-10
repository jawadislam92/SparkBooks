import Button from '@/Components/ui/Button';
import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import StatusChip, { BILL_STATUS_LABELS } from '@/Components/documents/StatusChip';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

interface BillRow {
    id: number;
    reference: string | null;
    status: string;
    contact_name: string;
    bill_date: string | null;
    due_date: string | null;
    /** Integer minor units. */
    total: number;
    /** Integer minor units. */
    balance: number;
}

interface IndexPageProps extends PageProps {
    bills: BillRow[];
}

type StatusFilter = 'all' | 'draft' | 'received' | 'partially_paid' | 'paid' | 'void';

const STATUS_FILTER_OPTIONS: { value: StatusFilter; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'draft', label: 'Draft' },
    { value: 'received', label: 'Received' },
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

export default function Index({ bills }: IndexPageProps) {
    const { currentCompany } = usePage<PageProps>().props;
    const currency = currentCompany?.base_currency ?? 'PKR';
    const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');

    const visibleBills = useMemo(
        () =>
            statusFilter === 'all' ? bills : bills.filter((bill) => bill.status === statusFilter),
        [bills, statusFilter],
    );

    return (
        <AppShell title="Bills">
            <Head title="Bills" />

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
                <Button type="button" onClick={() => router.visit(route('bills.new'))}>
                    New bill
                </Button>
            </div>

            <Sheet>
                {visibleBills.length === 0 ? (
                    <p className="py-8 text-center text-sm text-ink-700">
                        {bills.length === 0 ? 'No bills yet.' : 'No bills match this filter.'}
                    </p>
                ) : (
                    <table className="w-full border-collapse text-left text-sm">
                        <thead>
                            <tr className="border-b border-rule text-xs font-medium uppercase tracking-wide text-ink-700">
                                <th scope="col" className="py-2 pr-3 font-medium">
                                    Reference
                                </th>
                                <th scope="col" className="py-2 pr-3 font-medium">
                                    Vendor
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
                            {visibleBills.map((bill) => (
                                <tr
                                    key={bill.id}
                                    tabIndex={0}
                                    role="button"
                                    onClick={() => router.visit(route('bills.edit', bill.id))}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter' || event.key === ' ') {
                                            event.preventDefault();
                                            router.visit(route('bills.edit', bill.id));
                                        }
                                    }}
                                    className="cursor-pointer border-b border-rule text-ink transition duration-[120ms] ease-out motion-reduce:transition-none hover:bg-paper focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-spark last:border-b-0"
                                >
                                    <td className="py-3 pr-3 align-top font-mono text-xs">
                                        {bill.reference ?? '—'}
                                    </td>
                                    <td className="py-3 pr-3 align-top">{bill.contact_name}</td>
                                    <td className="py-3 pr-3 align-top text-ink-700">
                                        {formatDate(bill.due_date)}
                                    </td>
                                    <td className="py-3 pr-3 align-top">
                                        <StatusChip status={bill.status} labels={BILL_STATUS_LABELS} />
                                    </td>
                                    <td className="py-3 pl-3 align-top">
                                        <MoneyText minor={bill.total} currency={currency} size="sm" />
                                    </td>
                                    <td className="py-3 pl-3 align-top">
                                        <MoneyText minor={bill.balance} currency={currency} size="sm" />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Sheet>
        </AppShell>
    );
}
