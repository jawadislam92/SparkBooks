import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface BankAccountRow {
    id: number;
    name: string;
    currency_code: string;
    balance: number;
}

interface IndexPageProps extends PageProps {
    bankAccounts: BankAccountRow[];
}

/**
 * Pick-a-bank-account landing page for reconciliation (spec §7.9) — mirrors
 * Banking/Index's account list, minus the account-management actions (this
 * screen only ever links into Reconciliation/Show for the chosen account).
 */
export default function Index({ bankAccounts }: IndexPageProps) {
    return (
        <AppShell title="Reconciliation">
            <Head title="Reconciliation" />

            <Sheet title="Choose a bank account">
                {bankAccounts.length === 0 ? (
                    <p className="py-8 text-center text-sm text-ink-700">
                        No bank or cash accounts yet — add one from Banking first.
                    </p>
                ) : (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {bankAccounts.map((account) => (
                            <Link
                                key={account.id}
                                href={route('reconciliations.show', account.id)}
                                className="flex flex-col gap-3 rounded-md border border-rule p-4 transition duration-[120ms] ease-out motion-reduce:transition-none hover:border-spark focus:outline-none focus-visible:ring-2 focus-visible:ring-spark"
                            >
                                <div>
                                    <p className="font-sans text-sm font-semibold text-ink">
                                        {account.name}
                                    </p>
                                    <p className="mt-1 text-xs text-ink-700">
                                        {account.currency_code}
                                    </p>
                                </div>
                                <MoneyText
                                    minor={account.balance}
                                    currency={account.currency_code}
                                    size="lg"
                                    className="text-left"
                                />
                            </Link>
                        ))}
                    </div>
                )}
            </Sheet>
        </AppShell>
    );
}
