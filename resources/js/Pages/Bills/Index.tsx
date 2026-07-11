import Button from '@/Components/ui/Button';
import ExportCsvLink from '@/Components/ui/ExportCsvLink';
import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import StatusChip, { BILL_STATUS_LABELS } from '@/Components/documents/StatusChip';
import AppShell from '@/Layouts/AppShell';
import { isBillOverdue, todayIsoDate } from '@/lib/overdue';
import { summarizeList } from '@/lib/summarize';
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
    notes: string | null;
    categories: string[];
    /** Integer minor units. */
    total: number;
    /** Integer minor units. */
    balance: number;
}

interface IndexPageProps extends PageProps {
    bills: BillRow[];
}

type StatusFilter = 'all' | 'draft' | 'received' | 'partially_paid' | 'paid' | 'void' | 'overdue';

const STATUS_FILTER_OPTIONS: { value: StatusFilter; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'draft', label: 'Draft' },
    { value: 'received', label: 'Received' },
    { value: 'partially_paid', label: 'Partially paid' },
    { value: 'paid', label: 'Paid' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'void', label: 'Void' },
];

const SEARCH_INPUT_CLASSES =
    'h-10 w-64 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink placeholder:text-ink-700/50 transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

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

function OverdueTag() {
    return (
        <span className="ml-2 inline-block rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-ink">
            Overdue
        </span>
    );
}

/** Matches a row's vendor name, reference, or notes against the search text, case-insensitively. */
function matchesSearch(bill: BillRow, search: string): boolean {
    if (search === '') {
        return true;
    }
    const needle = search.toLowerCase();
    return (
        bill.contact_name.toLowerCase().includes(needle) ||
        (bill.reference?.toLowerCase().includes(needle) ?? false) ||
        (bill.notes?.toLowerCase().includes(needle) ?? false)
    );
}

/** Real server-side Bill status values the export endpoint accepts — excludes 'all' and the client-only 'overdue' derived filter. */
const EXPORTABLE_STATUSES: ReadonlySet<string> = new Set([
    'draft',
    'received',
    'partially_paid',
    'paid',
    'void',
]);

export default function Index({ bills }: IndexPageProps) {
    const { currentCompany, can } = usePage<PageProps>().props;
    const currency = currentCompany?.base_currency ?? 'PKR';
    const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');
    const [search, setSearch] = useState('');
    const today = useMemo(() => todayIsoDate(), []);

    // 'overdue' is a client-computed filter (due_date < today, still payable)
    // with no matching server-side Bill.status value, so it can't travel as
    // an export ?status= filter the way the other options can — the export
    // falls back to "all" rather than sending a value the endpoint would
    // reject with a 422.
    const exportHref = EXPORTABLE_STATUSES.has(statusFilter)
        ? `${route('bills.export')}?status=${encodeURIComponent(statusFilter)}`
        : route('bills.export');

    const visibleBills = useMemo(
        () =>
            bills
                .filter((bill) => {
                    if (statusFilter === 'all') {
                        return true;
                    }
                    if (statusFilter === 'overdue') {
                        return isBillOverdue(bill, today);
                    }
                    return bill.status === statusFilter;
                })
                .filter((bill) => matchesSearch(bill, search)),
        [bills, statusFilter, search, today],
    );

    return (
        <AppShell title="Bills">
            <Head title="Bills" />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-4">
                <div className="flex flex-wrap items-center gap-3">
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
                    <input
                        type="search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Search vendor, reference, or notes"
                        aria-label="Search bills"
                        className={SEARCH_INPUT_CLASSES}
                    />
                </div>
                <div className="flex items-center gap-2">
                    {can['exports.run'] && <ExportCsvLink href={exportHref} />}
                    <Button type="button" onClick={() => router.visit(route('bills.new'))}>
                        New bill
                    </Button>
                </div>
            </div>

            <Sheet>
                {visibleBills.length === 0 ? (
                    <div className="py-8 text-center text-sm text-ink-700">
                        {bills.length === 0 ? (
                            <div className="flex flex-col items-center gap-3">
                                <p>No bills yet.</p>
                                <Button type="button" onClick={() => router.visit(route('bills.new'))}>
                                    New bill
                                </Button>
                            </div>
                        ) : (
                            <p>No bills match this filter.</p>
                        )}
                    </div>
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
                                    Categories
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
                            {visibleBills.map((bill) => {
                                const overdue = isBillOverdue(bill, today);

                                return (
                                    <tr
                                        key={bill.id}
                                        tabIndex={0}
                                        role="button"
                                        onClick={() => router.visit(route('bills.show', bill.id))}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter' || event.key === ' ') {
                                                event.preventDefault();
                                                router.visit(route('bills.show', bill.id));
                                            }
                                        }}
                                        className={
                                            `cursor-pointer border-b border-rule text-ink transition duration-[120ms] ease-out motion-reduce:transition-none hover:bg-paper focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-spark last:border-b-0 ` +
                                            (overdue ? 'bg-amber-50/40' : '')
                                        }
                                    >
                                        <td className="py-3 pr-3 align-top font-mono text-xs">
                                            {bill.reference ?? '—'}
                                        </td>
                                        <td className="py-3 pr-3 align-top">{bill.contact_name}</td>
                                        <td className="py-3 pr-3 align-top text-ink-700">
                                            {formatDate(bill.due_date)}
                                            {overdue && <OverdueTag />}
                                        </td>
                                        <td className="py-3 pr-3 align-top text-ink-700">
                                            {summarizeList(bill.categories)}
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
                                );
                            })}
                        </tbody>
                    </table>
                )}
            </Sheet>
        </AppShell>
    );
}
