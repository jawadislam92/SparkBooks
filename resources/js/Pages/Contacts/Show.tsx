import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import {
    ContactType,
    TYPE_CHIP_CLASSES,
    TYPE_LABELS,
} from '@/lib/contactLabels';
import { PageProps } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

interface ContactHeader {
    id: number;
    type: ContactType;
    name: string;
    email: string | null;
    phone: string | null;
    billing_address: string | null;
    tax_number: string | null;
    notes: string | null;
    is_archived: boolean;
}

interface ShowPageProps extends PageProps {
    contact: ContactHeader;
    outstanding_balance: number;
}

function TypeChip({ type }: { type: string }) {
    return (
        <span
            className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${
                TYPE_CHIP_CLASSES[type] ?? 'bg-paper text-ink-700'
            }`}
        >
            {TYPE_LABELS[type] ?? type}
        </span>
    );
}

function DetailRow({ label, value }: { label: string; value: string | null }) {
    if (!value) {
        return null;
    }

    return (
        <div className="flex flex-col gap-0.5">
            <p className="text-xs font-medium uppercase tracking-wide text-ink-700">
                {label}
            </p>
            <p className="whitespace-pre-line text-sm text-ink">{value}</p>
        </div>
    );
}

export default function Show({ contact, outstanding_balance }: ShowPageProps) {
    const { currentCompany } = usePage<PageProps>().props;
    const currency = currentCompany?.base_currency ?? 'USD';

    return (
        <AppShell title={contact.name}>
            <Head title={contact.name} />

            <Link
                href={route('contacts.index')}
                className="mb-4 inline-block text-sm font-medium text-lagoon hover:underline"
            >
                &larr; Back to contacts
            </Link>

            <Sheet className="mb-6">
                <div className="flex items-start justify-between">
                    <div>
                        <div className="flex items-center gap-2">
                            <h2 className="font-display text-2xl font-semibold text-ink">
                                {contact.name}
                            </h2>
                            <TypeChip type={contact.type} />
                            {contact.is_archived && (
                                <span className="inline-block rounded-full bg-paper px-2 py-0.5 text-xs font-medium text-ink-700">
                                    Archived
                                </span>
                            )}
                        </div>
                    </div>
                    <div className="text-right">
                        <p className="text-xs font-medium uppercase tracking-wide text-ink-700">
                            Outstanding balance
                        </p>
                        <MoneyText minor={outstanding_balance} currency={currency} size="lg" />
                    </div>
                </div>

                <div className="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3">
                    <DetailRow label="Email" value={contact.email} />
                    <DetailRow label="Phone" value={contact.phone} />
                    <DetailRow label="Tax number" value={contact.tax_number} />
                    <DetailRow label="Billing address" value={contact.billing_address} />
                    <DetailRow label="Notes" value={contact.notes} />
                </div>
            </Sheet>

            <Sheet title="Activity">
                <p className="py-8 text-center text-sm text-ink-700">No transactions yet.</p>
            </Sheet>
        </AppShell>
    );
}
