import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import StatTile from '@/Components/ui/StatTile';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, usePage } from '@inertiajs/react';

interface DashboardPageProps extends PageProps {
    cash_position: number;
    customer_count: number;
    vendor_count: number;
    bank_account_count: number;
}

/**
 * Phase 2's "light" real-data pass (see docs/PROJECT_MAP.md §2 and
 * DashboardController): cash position + a couple of counts, no
 * debit/credit words. Real widgets (P&L, agings, cash flow) are Phase 5.
 */
export default function Dashboard({
    cash_position,
    customer_count,
    vendor_count,
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
