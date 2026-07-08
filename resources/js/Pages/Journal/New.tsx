import AppShell from '@/Layouts/AppShell';
import { Head } from '@inertiajs/react';

type AccountOption = {
    id: number;
    code: string;
    name: string;
    type: string;
};

// Placeholder for Task 7 — replaces this with the real new-entry form.
export default function New({ accounts }: { accounts: AccountOption[] }) {
    return (
        <AppShell title="New Journal Entry">
            <Head title="New Journal Entry" />
            <p className="text-sm text-ink-700">
                {accounts.length} account(s) available for manual journal
                lines.
            </p>
        </AppShell>
    );
}
