import Button from '@/Components/ui/Button';
import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import StatusChip, { EXPENSE_STATUS_LABELS } from '@/Components/documents/StatusChip';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

interface ExpenseRow {
    id: number;
    status: string;
    contact_name: string | null;
    expense_date: string | null;
    /** Integer minor units. */
    total: number;
}

interface IndexPageProps extends PageProps {
    expenses: ExpenseRow[];
}

type StatusFilter = 'all' | 'draft' | 'recorded' | 'void';

const STATUS_FILTER_OPTIONS: { value: StatusFilter; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'draft', label: 'Draft' },
    { value: 'recorded', label: 'Recorded' },
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

export default function Index({ expenses }: IndexPageProps) {
    const { currentCompany } = usePage<PageProps>().props;
    const currency = currentCompany?.base_currency ?? 'PKR';
    const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');

    const visibleExpenses = useMemo(
        () =>
            statusFilter === 'all'
                ? expenses
                : expenses.filter((expense) => expense.status === statusFilter),
        [expenses, statusFilter],
    );

    return (
        <AppShell title="Expenses">
            <Head title="Expenses" />

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
                <Button type="button" onClick={() => router.visit(route('expenses.new'))}>
                    New expense
                </Button>
            </div>

            <Sheet>
                {visibleExpenses.length === 0 ? (
                    <p className="py-8 text-center text-sm text-ink-700">
                        {expenses.length === 0
                            ? 'No expenses yet.'
                            : 'No expenses match this filter.'}
                    </p>
                ) : (
                    <table className="w-full border-collapse text-left text-sm">
                        <thead>
                            <tr className="border-b border-rule text-xs font-medium uppercase tracking-wide text-ink-700">
                                <th scope="col" className="py-2 pr-3 font-medium">
                                    Payee
                                </th>
                                <th scope="col" className="py-2 pr-3 font-medium">
                                    Date
                                </th>
                                <th scope="col" className="py-2 pr-3 font-medium">
                                    Status
                                </th>
                                <th scope="col" className="py-2 pl-3 text-right font-medium">
                                    Total
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {visibleExpenses.map((expense) => (
                                <tr
                                    key={expense.id}
                                    tabIndex={0}
                                    role="button"
                                    onClick={() => router.visit(route('expenses.edit', expense.id))}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter' || event.key === ' ') {
                                            event.preventDefault();
                                            router.visit(route('expenses.edit', expense.id));
                                        }
                                    }}
                                    className="cursor-pointer border-b border-rule text-ink transition duration-[120ms] ease-out motion-reduce:transition-none hover:bg-paper focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-spark last:border-b-0"
                                >
                                    <td className="py-3 pr-3 align-top">
                                        {expense.contact_name ?? (
                                            <span className="text-ink-700">No payee</span>
                                        )}
                                    </td>
                                    <td className="py-3 pr-3 align-top text-ink-700">
                                        {formatDate(expense.expense_date)}
                                    </td>
                                    <td className="py-3 pr-3 align-top">
                                        <StatusChip status={expense.status} labels={EXPENSE_STATUS_LABELS} />
                                    </td>
                                    <td className="py-3 pl-3 align-top">
                                        <MoneyText minor={expense.total} currency={currency} size="sm" />
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
