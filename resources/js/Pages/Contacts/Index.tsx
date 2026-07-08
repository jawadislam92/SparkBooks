import Button from '@/Components/ui/Button';
import Field from '@/Components/ui/Field';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import {
    Dialog,
    DialogBackdrop,
    DialogPanel,
    DialogTitle,
} from '@headlessui/react';
import { FormEventHandler, useMemo, useState } from 'react';

interface ContactRow {
    id: number;
    type: 'customer' | 'vendor' | 'both';
    name: string;
    email: string | null;
    phone: string | null;
    billing_address: string | null;
    tax_number: string | null;
    notes: string | null;
    is_archived: boolean;
}

interface IndexPageProps extends PageProps {
    contacts: ContactRow[];
}

const TYPE_LABELS: Record<string, string> = {
    customer: 'Customer',
    vendor: 'Vendor',
    both: 'Customer & vendor',
};

const TYPE_CHIP_CLASSES: Record<string, string> = {
    customer: 'bg-spark-50 text-ink',
    vendor: 'bg-lagoon/10 text-lagoon',
    both: 'bg-amber-50 text-ink',
};

const INPUT_CLASSES =
    'h-10 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

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

function ArchivedTag() {
    return (
        <span className="ml-2 inline-block rounded-full bg-paper px-2 py-0.5 text-xs font-medium text-ink-700">
            Archived
        </span>
    );
}

interface ContactFormData {
    type: string;
    name: string;
    email: string;
    phone: string;
    tax_number: string;
    billing_address: string;
    notes: string;
}

function ContactFormDialog({
    contact,
    onClose,
}: {
    /** Present => editing; absent => creating a new contact. */
    contact: ContactRow | null;
    onClose: () => void;
}) {
    const isEditing = contact !== null;

    const form = useForm<ContactFormData>({
        type: contact?.type ?? 'customer',
        name: contact?.name ?? '',
        email: contact?.email ?? '',
        phone: contact?.phone ?? '',
        tax_number: contact?.tax_number ?? '',
        billing_address: contact?.billing_address ?? '',
        notes: contact?.notes ?? '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        if (isEditing) {
            form.patch(route('contacts.update', contact.id), {
                preserveScroll: true,
                onSuccess: onClose,
            });
        } else {
            form.post(route('contacts.store'), {
                preserveScroll: true,
                onSuccess: onClose,
            });
        }
    };

    return (
        <Dialog open onClose={onClose} className="relative z-50">
            <DialogBackdrop className="fixed inset-0 bg-ink/30" />
            <div className="fixed inset-0 flex items-center justify-center p-4">
                <DialogPanel className="w-full max-w-md rounded-sheet bg-paper-raised p-6 shadow-sheet">
                    <DialogTitle className="font-display text-lg font-semibold text-ink">
                        {isEditing ? 'Edit contact' : 'New contact'}
                    </DialogTitle>

                    <form onSubmit={submit} className="mt-4 flex max-h-[70vh] flex-col gap-4 overflow-y-auto">
                        <Field label="Type" htmlFor="contact-type" error={errors.type}>
                            <select
                                id="contact-type"
                                value={form.data.type}
                                onChange={(event) => form.setData('type', event.target.value)}
                                className={INPUT_CLASSES}
                            >
                                <option value="customer">Customer</option>
                                <option value="vendor">Vendor</option>
                                <option value="both">Customer & vendor</option>
                            </select>
                        </Field>

                        <Field label="Name" htmlFor="contact-name" error={errors.name}>
                            <input
                                id="contact-name"
                                type="text"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                className={INPUT_CLASSES}
                            />
                        </Field>

                        <Field label="Email" htmlFor="contact-email" error={errors.email}>
                            <input
                                id="contact-email"
                                type="email"
                                value={form.data.email}
                                onChange={(event) => form.setData('email', event.target.value)}
                                className={INPUT_CLASSES}
                            />
                        </Field>

                        <Field label="Phone" htmlFor="contact-phone" error={errors.phone}>
                            <input
                                id="contact-phone"
                                type="text"
                                value={form.data.phone}
                                onChange={(event) => form.setData('phone', event.target.value)}
                                className={INPUT_CLASSES}
                            />
                        </Field>

                        <Field label="Tax number" htmlFor="contact-tax-number" error={errors.tax_number}>
                            <input
                                id="contact-tax-number"
                                type="text"
                                value={form.data.tax_number}
                                onChange={(event) => form.setData('tax_number', event.target.value)}
                                className={INPUT_CLASSES}
                            />
                        </Field>

                        <Field
                            label="Billing address"
                            htmlFor="contact-billing-address"
                            error={errors.billing_address}
                        >
                            <textarea
                                id="contact-billing-address"
                                value={form.data.billing_address}
                                onChange={(event) =>
                                    form.setData('billing_address', event.target.value)
                                }
                                rows={2}
                                className={`${INPUT_CLASSES} h-auto resize-none py-2`}
                            />
                        </Field>

                        <Field label="Notes" htmlFor="contact-notes" error={errors.notes}>
                            <textarea
                                id="contact-notes"
                                value={form.data.notes}
                                onChange={(event) => form.setData('notes', event.target.value)}
                                rows={2}
                                className={`${INPUT_CLASSES} h-auto resize-none py-2`}
                            />
                        </Field>

                        <div className="mt-2 flex justify-end gap-2">
                            <Button type="button" variant="quiet" onClick={onClose}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing
                                    ? 'Saving…'
                                    : isEditing
                                      ? 'Save changes'
                                      : 'Create contact'}
                            </Button>
                        </div>
                    </form>
                </DialogPanel>
            </div>
        </Dialog>
    );
}

export default function Index({ contacts }: IndexPageProps) {
    const [showArchived, setShowArchived] = useState(false);
    const [editingContact, setEditingContact] = useState<ContactRow | null>(null);
    const [creating, setCreating] = useState(false);

    const visibleContacts = useMemo(
        () => (showArchived ? contacts : contacts.filter((c) => !c.is_archived)),
        [contacts, showArchived],
    );

    function toggleArchive(contact: ContactRow) {
        const routeName = contact.is_archived ? 'contacts.unarchive' : 'contacts.archive';
        router.post(route(routeName, contact.id), {}, { preserveScroll: true });
    }

    return (
        <AppShell title="Contacts">
            <Head title="Contacts" />

            <div className="mb-4 flex items-center justify-between">
                <label className="flex items-center gap-2 text-sm text-ink-700">
                    <input
                        type="checkbox"
                        checked={showArchived}
                        onChange={(event) => setShowArchived(event.target.checked)}
                        className="h-4 w-4 rounded border-rule text-spark focus:ring-spark"
                    />
                    Show archived
                </label>
                <Button type="button" onClick={() => setCreating(true)}>
                    New contact
                </Button>
            </div>

            <Sheet>
                {visibleContacts.length === 0 ? (
                    <p className="py-8 text-center text-sm text-ink-700">
                        No contacts yet.
                    </p>
                ) : (
                    <table className="w-full border-collapse text-left text-sm">
                        <thead>
                            <tr className="border-b border-rule text-xs font-medium uppercase tracking-wide text-ink-700">
                                <th scope="col" className="py-2 pr-3 font-medium">
                                    Name
                                </th>
                                <th scope="col" className="py-2 pr-3 font-medium">
                                    Type
                                </th>
                                <th scope="col" className="py-2 pr-3 font-medium">
                                    Email
                                </th>
                                <th scope="col" className="py-2 pr-3 font-medium">
                                    Phone
                                </th>
                                <th scope="col" className="py-2 pl-3 font-medium">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {visibleContacts.map((contact) => (
                                <tr
                                    key={contact.id}
                                    tabIndex={0}
                                    role="button"
                                    onClick={() => router.visit(route('contacts.show', contact.id))}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter' || event.key === ' ') {
                                            event.preventDefault();
                                            router.visit(route('contacts.show', contact.id));
                                        }
                                    }}
                                    className={
                                        `cursor-pointer border-b border-rule text-ink transition duration-[120ms] ease-out motion-reduce:transition-none hover:bg-paper focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-spark last:border-b-0 ` +
                                        (contact.is_archived ? 'opacity-50' : '')
                                    }
                                >
                                    <td className="py-3 pr-3 align-top">
                                        <span className="inline-flex items-center gap-1.5">
                                            {contact.name}
                                            {contact.is_archived && <ArchivedTag />}
                                        </span>
                                    </td>
                                    <td className="py-3 pr-3 align-top">
                                        <TypeChip type={contact.type} />
                                    </td>
                                    <td className="py-3 pr-3 align-top text-ink-700">
                                        {contact.email ?? ''}
                                    </td>
                                    <td className="py-3 pr-3 align-top text-ink-700">
                                        {contact.phone ?? ''}
                                    </td>
                                    <td className="py-3 pl-3 align-top text-right">
                                        <div className="flex justify-end gap-1">
                                            <Button
                                                type="button"
                                                variant="quiet"
                                                size="sm"
                                                onClick={(event) => {
                                                    event.stopPropagation();
                                                    setEditingContact(contact);
                                                }}
                                            >
                                                Edit
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="quiet"
                                                size="sm"
                                                onClick={(event) => {
                                                    event.stopPropagation();
                                                    toggleArchive(contact);
                                                }}
                                            >
                                                {contact.is_archived ? 'Unarchive' : 'Archive'}
                                            </Button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Sheet>

            {creating && (
                <ContactFormDialog contact={null} onClose={() => setCreating(false)} />
            )}
            {editingContact && (
                <ContactFormDialog
                    contact={editingContact}
                    onClose={() => setEditingContact(null)}
                />
            )}
        </AppShell>
    );
}
