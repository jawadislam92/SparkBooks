import Button from '@/Components/ui/Button';
import Field from '@/Components/ui/Field';
import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import AttachmentSection, { AttachmentData } from '@/Components/documents/AttachmentSection';
import BillPaymentModal, { BillPaymentModalBankAccount } from '@/Components/documents/BillPaymentModal';
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

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }
    return new Date(`${value}T00:00:00`).toLocaleDateString('en-PK', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

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

interface BillPaymentData {
    id: number;
    payment_date: string;
    /** Integer minor units, base currency (bills are base-currency-only in this phase). */
    amount: number;
    status: 'posted' | 'void' | string;
    memo: string | null;
    bank_account: { id: number; name: string } | null;
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
    amount_paid: number;
    balance: number;
    lines: BillLineData[];
    payments: BillPaymentData[];
    attachments: AttachmentData[];
}

interface EditPageProps extends PageProps {
    bill: BillData | null;
    contacts: ContactOption[];
    categoryAccounts: CategoryAccountOption[];
    taxRates: TaxRateOption[];
    bankAccounts: BillPaymentModalBankAccount[];
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

interface VoidConfirmDialogProps {
    title: string;
    description: string;
    confirmLabel: string;
    processingLabel: string;
    onCancel: () => void;
    onConfirm: () => void;
    processing: boolean;
}

/**
 * Minimal void confirmation (Phase 4 Task 6), shared by both "void the whole
 * bill" and "reverse a single payment" — Task 9 polishes this into the full
 * accounting-correct flow (mirrors Components/invoices/VoidDialog, which
 * lists live payments inline; here the payments-first rule is enforced
 * server-side and surfaces as the error banner instead — see BillService::
 * void's BillHasLivePayments guard).
 */
function VoidConfirmDialog({
    title,
    description,
    confirmLabel,
    processingLabel,
    onCancel,
    onConfirm,
    processing,
}: VoidConfirmDialogProps) {
    return (
        <Dialog open onClose={onCancel} className="relative z-50">
            <DialogBackdrop className="fixed inset-0 bg-ink/30" />
            <div className="fixed inset-0 flex items-center justify-center p-4">
                <DialogPanel className="w-full max-w-sm rounded-sheet bg-paper-raised p-6 shadow-sheet">
                    <DialogTitle className="font-display text-lg font-semibold text-ink">
                        {title}
                    </DialogTitle>
                    <p className="mt-2 text-sm text-ink-700">{description}</p>
                    <div className="mt-5 flex justify-end gap-2">
                        <Button type="button" variant="quiet" onClick={onCancel}>
                            Cancel
                        </Button>
                        <Button type="button" variant="danger" onClick={onConfirm} disabled={processing}>
                            {processing ? processingLabel : confirmLabel}
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

export default function Edit({ bill, contacts, categoryAccounts, taxRates, bankAccounts }: EditPageProps) {
    const { currentCompany, can, errors: pageErrors } = usePage<PageProps>().props;
    const currency = currentCompany?.base_currency ?? 'PKR';
    const receiveError = (pageErrors as Record<string, string | undefined> | undefined)?.status;
    const isEditing = bill !== null;
    const isDraft = bill === null || bill.status === 'draft';
    const canReceivePayment = bill !== null && (bill.status === 'received' || bill.status === 'partially_paid');
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [receiving, setReceiving] = useState(false);
    const [paymentModalOpen, setPaymentModalOpen] = useState(false);
    // is_opening bills (Phase 4 Task 7) are never voidable from here — mirrors
    // Invoices/Show's identical `canVoid && !invoice.is_opening` gating.
    // BillService::void refuses them server-side too (BillIsOpening, Task 6);
    // hiding the button is just the friendlier UX layer on top of that.
    const isVoidable =
        bill !== null &&
        !bill.is_opening &&
        (bill.status === 'received' || bill.status === 'partially_paid' || bill.status === 'paid');
    const [confirmingVoid, setConfirmingVoid] = useState(false);
    const [voiding, setVoiding] = useState(false);
    // Which payment (by id) is mid-confirm for "reverse this payment" — null
    // when no payment-void confirmation is open.
    const [confirmingVoidPaymentId, setConfirmingVoidPaymentId] = useState<number | null>(null);
    const [voidingPayment, setVoidingPayment] = useState(false);

    // Minimal wiring ahead of the full Show page (Task 9): mirrors how
    // Invoices/Edit carried its own Send action before Invoices/Show existed
    // (Phase 3 T3/T11), and Expenses/Edit's Record button (this same Phase 4
    // task). Posts BillController::receive with a fresh idempotency key per
    // click and reloads this same page.
    function receiveBill() {
        if (!bill) {
            return;
        }
        setReceiving(true);
        router.post(
            route('bills.receive', bill.id),
            {},
            {
                headers: { 'X-Idempotency-Key': crypto.randomUUID() },
                preserveScroll: true,
                onFinish: () => setReceiving(false),
            },
        );
    }

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

    // Posts BillController::void with a fresh idempotency key per click —
    // mirrors receiveBill()'s own shape. BillService::void refuses (with a
    // friendly error banner) while any payment is still posted — the
    // payments-first rule, spec §6 — so voiding a paid bill means reversing
    // each payment below first.
    function confirmVoid() {
        if (!bill) {
            return;
        }
        setVoiding(true);
        router.post(
            route('bills.void', bill.id),
            {},
            {
                headers: { 'X-Idempotency-Key': crypto.randomUUID() },
                preserveScroll: true,
                onFinish: () => {
                    setVoiding(false);
                    setConfirmingVoid(false);
                },
            },
        );
    }

    // Posts BillController::voidPayment for one payment — mirrors
    // confirmVoid()'s own shape.
    function confirmVoidPayment(paymentId: number) {
        if (!bill) {
            return;
        }
        setVoidingPayment(true);
        router.post(
            route('bills.payments.void', { bill: bill.id, payment: paymentId }),
            {},
            {
                headers: { 'X-Idempotency-Key': crypto.randomUUID() },
                preserveScroll: true,
                onFinish: () => {
                    setVoidingPayment(false);
                    setConfirmingVoidPaymentId(null);
                },
            },
        );
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
                        href={route('bills.index')}
                        className="shrink-0 font-medium text-lagoon hover:underline"
                    >
                        Back to bills &rarr;
                    </Link>
                </div>
            )}

            {bill?.is_opening && (
                <p className="mb-4 rounded-md bg-paper px-3 py-2 text-sm text-ink-700">
                    Opening bill — manage via opening balances, not here. It can&apos;t be voided from
                    this screen.
                </p>
            )}

            {receiveError && (
                <p role="alert" className="mb-4 rounded-md bg-clay-50 px-3 py-2 text-sm text-clay">
                    {receiveError}
                </p>
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
                                {isEditing && can['bills.receive'] && (
                                    <Button type="button" onClick={receiveBill} disabled={receiving}>
                                        {receiving ? 'Receiving…' : 'Receive bill'}
                                    </Button>
                                )}
                            </div>
                        ) : (
                            <div className="flex items-center gap-2">
                                {isVoidable && can['bills.void'] && (
                                    <Button
                                        type="button"
                                        variant="danger"
                                        onClick={() => setConfirmingVoid(true)}
                                    >
                                        Void bill
                                    </Button>
                                )}
                                {canReceivePayment && can['payments.record'] && (
                                    <Button type="button" onClick={() => setPaymentModalOpen(true)}>
                                        Record payment
                                    </Button>
                                )}
                            </div>
                        )
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
                        {!isDraft && bill && (
                            <>
                                <div className="flex w-full max-w-xs items-center justify-between text-sm text-ink-700">
                                    <span>Paid</span>
                                    <MoneyText minor={bill.amount_paid} currency={currency} size="sm" />
                                </div>
                                <div className="flex w-full max-w-xs items-center justify-between text-sm font-semibold text-ink">
                                    <span>Balance</span>
                                    <MoneyText minor={bill.balance} currency={currency} size="sm" />
                                </div>
                            </>
                        )}
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

            {!isDraft && bill && (
                <Sheet title="Payments" className="mt-6">
                    {bill.payments.length === 0 ? (
                        <p className="py-8 text-center text-sm text-ink-700">No payments recorded yet.</p>
                    ) : (
                        <table className="w-full border-collapse text-left text-sm">
                            <thead>
                                <tr className="border-b border-rule text-xs font-medium uppercase tracking-wide text-ink-700">
                                    <th scope="col" className="py-2 pr-3 font-medium">Date</th>
                                    <th scope="col" className="py-2 pr-3 font-medium">Account</th>
                                    <th scope="col" className="py-2 pr-3 font-medium">Status</th>
                                    <th scope="col" className="py-2 pr-3 text-right font-medium">Amount</th>
                                    {can['bills.void'] && <th scope="col" className="py-2 pl-3" />}
                                </tr>
                            </thead>
                            <tbody>
                                {bill.payments.map((payment) => (
                                    <tr key={payment.id} className="border-b border-rule last:border-b-0">
                                        <td className="py-3 pr-3 align-top text-ink-700">
                                            {formatDate(payment.payment_date)}
                                        </td>
                                        <td className="py-3 pr-3 align-top">
                                            {payment.bank_account?.name ?? '—'}
                                        </td>
                                        <td className="py-3 pr-3 align-top">
                                            {payment.status === 'void' ? (
                                                <span className="inline-block rounded-full bg-clay-50 px-2 py-0.5 text-xs font-medium text-clay">
                                                    Reversed
                                                </span>
                                            ) : (
                                                <span className="inline-block rounded-full bg-spark-50 px-2 py-0.5 text-xs font-medium text-ink">
                                                    Posted
                                                </span>
                                            )}
                                        </td>
                                        <td className="py-3 pr-3 align-top text-right">
                                            <MoneyText minor={payment.amount} currency={currency} size="sm" />
                                        </td>
                                        {can['bills.void'] && (
                                            <td className="py-3 pl-3 align-top text-right">
                                                {payment.status !== 'void' && (
                                                    <Button
                                                        type="button"
                                                        variant="quiet"
                                                        onClick={() => setConfirmingVoidPaymentId(payment.id)}
                                                    >
                                                        Reverse
                                                    </Button>
                                                )}
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </Sheet>
            )}

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

            {confirmingVoid && (
                <VoidConfirmDialog
                    title="Void this bill?"
                    description="This posts a reversing entry that clears the vendor's outstanding balance on the books. The bill stays visible, marked Void, but this cannot be undone — to receive it again you'll need a new bill. If any payment is still posted, voiding is refused until you reverse it below first."
                    confirmLabel="Void bill"
                    processingLabel="Voiding…"
                    onCancel={() => setConfirmingVoid(false)}
                    onConfirm={confirmVoid}
                    processing={voiding}
                />
            )}

            {confirmingVoidPaymentId !== null && (
                <VoidConfirmDialog
                    title="Reverse this payment?"
                    description="This posts a reversing entry that undoes the payment and reopens the bill's balance by that amount. This cannot be undone."
                    confirmLabel="Reverse payment"
                    processingLabel="Reversing…"
                    onCancel={() => setConfirmingVoidPaymentId(null)}
                    onConfirm={() => confirmVoidPayment(confirmingVoidPaymentId)}
                    processing={voidingPayment}
                />
            )}

            {paymentModalOpen && bill && (
                <BillPaymentModal
                    bill={{ id: bill.id, reference: bill.reference, balance: bill.balance }}
                    bankAccounts={bankAccounts}
                    currency={currency}
                    onClose={() => setPaymentModalOpen(false)}
                />
            )}
        </AppShell>
    );
}
