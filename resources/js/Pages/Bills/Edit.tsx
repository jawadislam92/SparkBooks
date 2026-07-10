import Button from '@/Components/ui/Button';
import Field from '@/Components/ui/Field';
import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import AttachmentSection, { AttachmentData } from '@/Components/documents/AttachmentSection';
import DocumentLineEditor, {
    CategoryAccountOption,
    DocumentLineRow,
    TaxRateOption,
    buildDocumentLinePayload,
    computeDocumentTotals,
    emptyDocumentLineRow,
} from '@/Components/documents/DocumentLineEditor';
import { BILL_STATUS_LABELS } from '@/Components/documents/StatusChip';
import AppShell from '@/Layouts/AppShell';
import { minorToDecimalString } from '@/lib/money';
import { PageProps } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Dialog,
    DialogBackdrop,
    DialogPanel,
    DialogTitle,
} from '@headlessui/react';
import { FormEventHandler, useMemo, useState } from 'react';

interface ContactOption {
    id: number;
    name: string;
    type: string;
}

interface BillLineData {
    id: number;
    description: string;
    category_account_id: number | null;
    tax_rate_id: number | null;
    amount: number;
    line_tax: number;
}

interface BillData {
    id: number;
    status: string;
    is_opening: boolean;
    contact_id: number;
    reference: string | null;
    bill_date: string | null;
    due_date: string | null;
    notes: string | null;
    subtotal: number;
    tax_total: number;
    total: number;
    lines: BillLineData[];
    attachments: AttachmentData[];
}

interface EditPageProps extends PageProps {
    bill: BillData | null;
    contacts: ContactOption[];
    categoryAccounts: CategoryAccountOption[];
    taxRates: TaxRateOption[];
}

const INPUT_CLASSES =
    'h-10 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark disabled:cursor-not-allowed disabled:opacity-60';

interface DeleteConfirmDialogProps {
    onCancel: () => void;
    onConfirm: () => void;
    processing: boolean;
}

function DeleteConfirmDialog({ onCancel, onConfirm, processing }: DeleteConfirmDialogProps) {
    return (
        <Dialog open onClose={onCancel} className="relative z-50">
            <DialogBackdrop className="fixed inset-0 bg-ink/30" />
            <div className="fixed inset-0 flex items-center justify-center p-4">
                <DialogPanel className="w-full max-w-sm rounded-sheet bg-paper-raised p-6 shadow-sheet">
                    <DialogTitle className="font-display text-lg font-semibold text-ink">
                        Delete this draft?
                    </DialogTitle>
                    <p className="mt-2 text-sm text-ink-700">
                        This bill draft will be permanently deleted. This cannot be undone.
                    </p>
                    <div className="mt-5 flex justify-end gap-2">
                        <Button type="button" variant="quiet" onClick={onCancel}>
                            Cancel
                        </Button>
                        <Button type="button" variant="danger" onClick={onConfirm} disabled={processing}>
                            {processing ? 'Deleting…' : 'Delete draft'}
                        </Button>
                    </div>
                </DialogPanel>
            </div>
        </Dialog>
    );
}

interface BillFormData {
    contact_id: string;
    reference: string;
    bill_date: string;
    due_date: string;
    notes: string;
}

/**
 * The draft bill editor (Phase 4 Task 2, polished to Invoices/Edit parity in
 * Task 9). As of Task 9, Receive/Record payment/Void all moved to Bills/Show
 * (the canonical landing page for a posted bill) — mirrors Invoices/Edit
 * exactly, which has never carried Send/Record payment/Void of its own
 * (Invoices/Show has always owned those, since Phase 3 T3/T11). This page is
 * purely the draft field/line editor: save/delete a draft, plus a read-only
 * view + "View bill" link once the bill has left draft status. The
 * opening-bill banner is likewise relocated to Show this same task — an
 * is_opening bill is never draft in the first place, so it has nothing to
 * say here anymore.
 */
export default function Edit({ bill, contacts, categoryAccounts, taxRates }: EditPageProps) {
    const { currentCompany, can } = usePage<PageProps>().props;
    const currency = currentCompany?.base_currency ?? 'PKR';
    const isEditing = bill !== null;
    const isDraft = bill === null || bill.status === 'draft';
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);

    const [rows, setRows] = useState<DocumentLineRow[]>(() =>
        bill && bill.lines.length > 0
            ? bill.lines.map((line) => ({
                  key: crypto.randomUUID(),
                  description: line.description,
                  categoryAccountId: line.category_account_id,
                  taxRateId: line.tax_rate_id,
                  amountText: minorToDecimalString(line.amount),
              }))
            : [emptyDocumentLineRow()],
    );

    const form = useForm<BillFormData>({
        contact_id: bill ? String(bill.contact_id) : '',
        reference: bill?.reference ?? '',
        bill_date: bill?.bill_date ?? '',
        due_date: bill?.due_date ?? '',
        notes: bill?.notes ?? '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const totals = useMemo(() => computeDocumentTotals(rows, taxRates), [rows, taxRates]);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.transform((data) => ({
            contact_id: data.contact_id === '' ? null : Number(data.contact_id),
            reference: data.reference === '' ? null : data.reference,
            bill_date: data.bill_date === '' ? null : data.bill_date,
            due_date: data.due_date === '' ? null : data.due_date,
            notes: data.notes === '' ? null : data.notes,
            lines: buildDocumentLinePayload(rows),
        }));

        if (isEditing) {
            form.patch(route('bills.update', bill!.id), { preserveScroll: true });
        } else {
            form.post(route('bills.store'), { preserveScroll: true });
        }
    };

    function confirmDelete() {
        if (!bill) {
            return;
        }
        setDeleting(true);
        router.delete(route('bills.destroy', bill.id), {
            onFinish: () => setDeleting(false),
        });
    }

    const pageTitle = isEditing ? (bill!.reference ?? 'Bill') : 'New bill';

    return (
        <AppShell title="Bills">
            <Head title={pageTitle} />

            {!isDraft && bill && (
                <div className="mb-4 flex items-center justify-between gap-4 rounded-md border border-rule bg-paper-raised px-4 py-3 text-sm text-ink-700">
                    <span>
                        This bill is {BILL_STATUS_LABELS[bill.status] ?? bill.status} and can no longer
                        be edited here.
                    </span>
                    <Link
                        href={route('bills.show', bill.id)}
                        className="shrink-0 font-medium text-lagoon hover:underline"
                    >
                        View bill &rarr;
                    </Link>
                </div>
            )}

            <form onSubmit={submit}>
                <Sheet
                    title={pageTitle}
                    actions={
                        isDraft ? (
                            <div className="flex items-center gap-2">
                                {isEditing && (
                                    <Button
                                        type="button"
                                        variant="quiet"
                                        onClick={() => setConfirmingDelete(true)}
                                    >
                                        Delete draft
                                    </Button>
                                )}
                                <Button type="submit" disabled={form.processing}>
                                    {form.processing ? 'Saving…' : 'Save draft'}
                                </Button>
                            </div>
                        ) : undefined
                    }
                >
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Field label="Vendor" htmlFor="bill-contact" error={errors.contact_id}>
                            <select
                                id="bill-contact"
                                value={form.data.contact_id}
                                disabled={!isDraft}
                                onChange={(event) => form.setData('contact_id', event.target.value)}
                                className={INPUT_CLASSES}
                            >
                                <option value="">Select a vendor</option>
                                {contacts.map((contact) => (
                                    <option key={contact.id} value={contact.id}>
                                        {contact.name}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Reference (optional)" htmlFor="bill-reference" error={errors.reference}>
                            <input
                                id="bill-reference"
                                type="text"
                                value={form.data.reference}
                                disabled={!isDraft}
                                onChange={(event) => form.setData('reference', event.target.value)}
                                placeholder="Vendor's bill number"
                                className={INPUT_CLASSES}
                            />
                        </Field>

                        <Field label="Bill date" htmlFor="bill-date" error={errors.bill_date}>
                            <input
                                id="bill-date"
                                type="date"
                                value={form.data.bill_date}
                                disabled={!isDraft}
                                onChange={(event) => form.setData('bill_date', event.target.value)}
                                className={INPUT_CLASSES}
                            />
                        </Field>

                        <Field label="Due date" htmlFor="bill-due-date" error={errors.due_date}>
                            <input
                                id="bill-due-date"
                                type="date"
                                value={form.data.due_date}
                                disabled={!isDraft}
                                onChange={(event) => form.setData('due_date', event.target.value)}
                                className={INPUT_CLASSES}
                            />
                        </Field>
                    </div>

                    <div className="mt-6">
                        <h3 className="mb-2 font-display text-sm font-semibold text-ink">Line items</h3>
                        {isDraft ? (
                            <DocumentLineEditor
                                rows={rows}
                                categoryAccounts={categoryAccounts}
                                taxRates={taxRates}
                                currency={currency}
                                onChange={setRows}
                                errors={errors}
                            />
                        ) : (
                            <table className="w-full border-collapse text-left text-sm">
                                <thead>
                                    <tr className="border-b border-rule text-xs font-medium uppercase tracking-wide text-ink-700">
                                        <th className="py-2 pr-3 font-medium">Description</th>
                                        <th className="py-2 pl-3 text-right font-medium">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {bill!.lines.map((line) => (
                                        <tr key={line.id} className="border-b border-rule last:border-b-0">
                                            <td className="py-3 pr-3">{line.description}</td>
                                            <td className="py-3 pl-3">
                                                <MoneyText
                                                    minor={line.amount + line.line_tax}
                                                    currency={currency}
                                                    size="sm"
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>

                    <div className="mt-6 flex flex-col items-end gap-1 border-t border-rule pt-4">
                        <div className="flex w-full max-w-xs items-center justify-between text-sm text-ink-700">
                            <span>Subtotal</span>
                            <MoneyText
                                minor={isDraft ? totals.subtotal : bill!.subtotal}
                                currency={currency}
                                size="sm"
                            />
                        </div>
                        <div className="flex w-full max-w-xs items-center justify-between text-sm text-ink-700">
                            <span>Tax</span>
                            <MoneyText
                                minor={isDraft ? totals.taxTotal : bill!.tax_total}
                                currency={currency}
                                size="sm"
                            />
                        </div>
                        <div className="flex w-full max-w-xs items-center justify-between text-base font-semibold text-ink">
                            <span>Total</span>
                            <MoneyText
                                minor={isDraft ? totals.total : bill!.total}
                                currency={currency}
                                size="md"
                            />
                        </div>
                    </div>

                    <Field label="Notes" htmlFor="bill-notes" error={errors.notes} className="mt-6">
                        <textarea
                            id="bill-notes"
                            value={form.data.notes}
                            disabled={!isDraft}
                            onChange={(event) => form.setData('notes', event.target.value)}
                            rows={3}
                            placeholder="Optional note for your own records"
                            className={`${INPUT_CLASSES} h-auto resize-none py-2`}
                        />
                    </Field>
                </Sheet>
            </form>

            {isEditing && (
                <Sheet className="mt-6">
                    <AttachmentSection
                        attachments={bill!.attachments}
                        uploadUrl={route('bills.attachments.store', bill!.id)}
                        downloadUrl={(attachmentId) => route('bills.attachments.download', [bill!.id, attachmentId])}
                        destroyUrl={(attachmentId) => route('bills.attachments.destroy', [bill!.id, attachmentId])}
                        canUpload={can['bills.draft']}
                        canDelete={can['bills.void']}
                    />
                </Sheet>
            )}

            {confirmingDelete && (
                <DeleteConfirmDialog
                    onCancel={() => setConfirmingDelete(false)}
                    onConfirm={confirmDelete}
                    processing={deleting}
                />
            )}
        </AppShell>
    );
}
