import DocumentSummaryCard from '@/Components/dashboard/DocumentSummaryCard';
import IncomeExpenseChart from '@/Components/dashboard/IncomeExpenseChart';
import PlSnapshotCard from '@/Components/dashboard/PlSnapshotCard';
import RecentActivityCard, { RecentActivityItem } from '@/Components/dashboard/RecentActivityCard';
import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import StatTile from '@/Components/ui/StatTile';
import { IncomeExpenseChartPayload } from '@/lib/incomeExpenseChartData';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, usePage } from '@inertiajs/react';

interface DocumentSummary {
    outstanding_count: number;
    outstanding_total: number;
    overdue_count: number;
    overdue_total: number;
}

interface PlSnapshot {
    period_label: string;
    from: string;
    to: string;
    income: number;
    expenses: number;
    net_profit: number;
}

interface DashboardPageProps extends PageProps {
    cash_position: number;
    customer_count: number;
    vendor_count: number;
    bank_account_count: number;
    invoices: DocumentSummary;
    bills: DocumentSummary;
    pl_snapshot: PlSnapshot;
    income_expense_chart: IncomeExpenseChartPayload;
    recent_activity: RecentActivityItem[];
}

/**
 * Phase 2 gave this a "light" real-data pass (cash position + a couple of
 * counts). Phase 5 Task 7 enriches it to spec §7.12: outstanding/overdue
 * invoices, unpaid bills, a P&L snapshot, an income-vs-expense chart, and a
 * gated recent-activity feed — see DashboardController for how each figure
 * is derived (always from an existing report service/model, never a fresh
 * ledger query invented for this screen).
 */
export default function Dashboard({
    cash_position,
    customer_count,
    vendor_count,
    invoices,
    bills,
    pl_snapshot,
    income_expense_chart,
    recent_activity,
}: DashboardPageProps) {
    const { currentCompany } = usePage<PageProps>().props;
    const baseCurrency = currentCompany?.base_currency ?? 'USD';
    const isEmpty = cash_position === 0 && customer_count === 0 && vendor_count === 0;

    return (
        <AppShell title="Dashboard">
            <Head title="Dashboard" />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <StatTile label="Cash position">
                    <MoneyText
                        minor={cash_position}
                        currency={baseCurrency}
                        size="lg"
                        className="text-left"
                    />
                </StatTile>
                <StatTile label="Customers">
                    <p className="font-display text-2xl font-semibold text-ink">
                        {customer_count}
                    </p>
                </StatTile>
                <StatTile label="Vendors">
                    <p className="font-display text-2xl font-semibold text-ink">
                        {vendor_count}
                    </p>
                </StatTile>
            </div>

            <div className="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <DocumentSummaryCard
                    title="Invoices"
                    noun="invoice"
                    outstandingCount={invoices.outstanding_count}
                    outstandingTotal={invoices.outstanding_total}
                    overdueCount={invoices.overdue_count}
                    overdueTotal={invoices.overdue_total}
                    currency={baseCurrency}
                    href={route('invoices.index')}
                    linkLabel="View invoices"
                />
                <DocumentSummaryCard
                    title="Bills"
                    noun="bill"
                    outstandingCount={bills.outstanding_count}
                    outstandingTotal={bills.outstanding_total}
                    overdueCount={bills.overdue_count}
                    overdueTotal={bills.overdue_total}
                    currency={baseCurrency}
                    href={route('bills.index')}
                    linkLabel="View bills"
                />
            </div>

            <div className="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="lg:col-span-1">
                    <PlSnapshotCard
                        periodLabel={pl_snapshot.period_label}
                        from={pl_snapshot.from}
                        to={pl_snapshot.to}
                        income={pl_snapshot.income}
                        expenses={pl_snapshot.expenses}
                        netProfit={pl_snapshot.net_profit}
                        currency={baseCurrency}
                    />
                </div>
                <div className="lg:col-span-2">
                    <Sheet title="Income vs expense">
                        <IncomeExpenseChart data={income_expense_chart} currency={baseCurrency} />
                    </Sheet>
                </div>
            </div>

            <div className="mb-6">
                <RecentActivityCard items={recent_activity} />
            </div>

            {isEmpty && (
                <Sheet>
                    <div className="flex flex-col items-center gap-2 py-16 text-center">
                        <p className="font-display text-lg font-semibold text-ink">
                            Your books are ready.
                        </p>
                        <p className="max-w-sm text-sm text-ink-700">
                            Add your bank accounts and contacts to get
                            started tracking your finances here.
                        </p>
                    </div>
                </Sheet>
            )}
        </AppShell>
    );
}
