import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { Head } from '@inertiajs/react';

export default function Dashboard() {
    return (
        <AppShell title="Dashboard">
            <Head title="Dashboard" />

            <Sheet>
                <div className="flex flex-col items-center gap-2 py-16 text-center">
                    <p className="font-display text-lg font-semibold text-ink">
                        Your books are ready.
                    </p>
                    <p className="max-w-sm text-sm text-ink-700">
                        Add your chart of accounts and record your first
                        entries to start tracking your finances here.
                    </p>
                </div>
            </Sheet>
        </AppShell>
    );
}
