import AppShell from '@/Layouts/AppShell';
import { Head } from '@inertiajs/react';

// Placeholder for Task 7 — replaces this with the real journal entries list.
export default function Index() {
    return (
        <AppShell title="Journal Entries">
            <Head title="Journal Entries" />
            <p className="text-sm text-ink-700">
                Manual journal entries will be listed here.
            </p>
        </AppShell>
    );
}
