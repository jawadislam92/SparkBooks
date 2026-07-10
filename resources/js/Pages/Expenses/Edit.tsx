import Button from '@/Components/ui/Button';
import Field from '@/Components/ui/Field';
import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import DocumentLineEditor, {
    CategoryAccountOption,
    DocumentLineRow,
    TaxRateOption,
    buildDocumentLinePayload,
    computeDocumentTotals,
    emptyDocumentLineRow,
} from '@/Components/documents/DocumentLineEditor';
import { EXPENSE_STATUS_LABELS } from '@/Components/documents/StatusChip';
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

interface BankAccountOption {
    id: number;
    name: string;
}

interface ExpenseLineData {
    id: number;
    description: string;
    category_account_id: number | null;
    tax_rate_id: number | null;
    amount: number;
    line_tax: number;
}

interface ExpenseData {
    id: number;
    status: string;
    contact_id: number | null;
    expense_date: string | null;
    bank_account_id: number | null;
    notes: string | null;
    subtotal: number;
    tax_total: number;
    total: number;
    lines: ExpenseLineData[];
}

interface EditPageProps extends PageProps {
    expense: ExpenseData | null;
    contacts: ContactOption[];
    bankAccounts: BankAccountOption[];
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
                        This expense draft will be permanently deleted. This cannot be undone.
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
    onCancel: () => void;
    onConfirm: () => void;
    processing: boolean;
}

/**
 * Minimal void confirmation (Phase 4 Task 6) — Task 9 polishes this into the
 * full accounting-correct flow (mirrors Components/invoices/VoidDialog).
 * Posted entries are immutable; voiding reverses the P4/P5 entry rather than
 * editing it, so this is worth a confirm step exactly like deleting a draft.
 */
function VoidConfirmDialog({ onCancel, onConfirm, processing }: VoidConfirmDialogProps) {
    return (
        <Dialog open onClose={onCancel} className="relative z-50">
            <DialogBackdrop className="fixed inset-0 bg-ink/30" />
            <div className="fixed inset-0 flex items-center justify-center p-4">
                <DialogPanel className="w-full max-w-sm rounded-sheet bg-paper-raised p-6 shadow-sheet">
                    <DialogTitle className="font-display text-lg font-semibold text-ink">
                        Void this expense?
                    </DialogTitle>
                    <p className="mt-2 text-sm text-ink-700">
                        This posts a reversing entry that undoes the expense on the books. The
                        expense stays visible, marked Void, but this cannot be undone — to record
                        it again you&apos;ll need a new expense.
                    </p>
                    <div className="mt-5 flex justify-end gap-2">
                        <Button type="button" variant="quiet" onClick={onCancel}>
                            Cancel
                        </Button>
                        <Button type="button" variant="danger" onClick={onConfirm} disabled={processing}>
                            {processing ? 'Voiding…' : 'Void expense'}
                        </Button>
                    </div>
                </DialogPanel>
            </div>
        </Dialog>
    );
}

interface ExpenseFormData {
    contact_id: string;
    expense_date: string;
    bank_account_id: string;
    notes: string;
}

export default function Edit({ expense, contacts, bankAccounts, categoryAccounts, taxRates }: EditPageProps) {
    const { currentCompany, can, errors: pageErrors } = usePage<PageProps>().props;
    const currency = currentCompany?.base_currency ?? 'PKR';
    const recordError = (pageErrors as Record<string, string | undefined> | undefined)?.status;
    const isEditing = expense !== null;
    const isDraft = expense === null || expense.status === 'draft';
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [recording, setRecording] = useState(false);
    const [confirmingVoid, setConfirmingVoid] = useState(false);
    const [voiding, setVoiding] = useState(false);
    const isRecorded = expense !== null && expense.status === 'recorded';

    // Minimal wiring ahead of the full Show page (Task 9): mirrors how
    // Invoices/Edit carried its own Send action before Invoices/Show existed
    // (Phase 3 T3/T11). Posts ExpenseController::record with a fresh
    // idempotency key per click and reloads this same page — the
    // now-Recorded expense renders read-only via the isDraft checks below.
    function recordExpense() {
        if (!expense) {
            return;
        }
        setRecording(true);
        router.post(
            route('expenses.record', expense.id),
            {},
            {
                headers: { 'X-Idempotency-Key': crypto.randomUUID() },
                preserveScroll: true,
                onFinish: () => setRecording(false),
            },
        );
    }

    const [rows, setRows] = useState<DocumentLineRow[]>(() =>
        expense && expense.lines.length > 0
            ? expense.lines.map((line) => ({
                  key: crypto.randomUUID(),
                  description: line.description,
                  categoryAccountId: line.category_account_id,
                  taxRateId: line.tax_rate_id,
                  amountText: minorToDecimalString(line.amount),
              }))
            : [emptyDocumentLineRow()],
    );

    const form = useForm<ExpenseFormData>({
        contact_id: expense?.contact_id ? String(expense.contact_id) : '',
        expense_date: expense?.expense_date ?? '',
        bank_account_id: expense?.bank_account_id ? String(expense.bank_account_id) : '',
        notes: expense?.notes ?? '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const totals = useMemo(() => computeDocumentTotals(rows, taxRates), [rows, taxRates]);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.transform((data) => ({
            contact_id: data.contact_id === '' ? null : Number(data.contact_id),
            expense_date: data.expense_date === '' ? null : data.expense_date,
            bank_account_id: data.bank_account_id === '' ? null : Number(data.bank_account_id),
            notes: data.notes === '' ? null : data.notes,
            lines: buildDocumentLinePayload(rows),
        }));

        if (isEditing) {
            form.patch(route('expenses.update', expense!.id), { preserveScroll: true });
        } else {
            form.post(route('expenses.store'), { preserveScroll: true });
        }
    };

    function confirmDelete() {
        if (!expense) {
            return;
        }
        setDeleting(true);
        router.delete(route('expenses.destroy', expense.id), {
            onFinish: () => setDeleting(false),
        });
    }

    // Posts ExpenseController::void with a fresh idempotency key per click —
    // mirrors recordExpense()'s own shape.
    function confirmVoid() {
        if (!expense) {
            return;
        }
        setVoiding(true);
        router.post(
            route('expenses.void', expense.id),
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

    const pageTitle = isEditing ? 'Expense' : 'New expense';

    return (
        <AppShell title="Expenses">
            <Head title={pageTitle} />

            {!isDraft && expense && (
                <div className="mb-4 flex items-center justify-between gap-4 rounded-md border border-rule bg-paper-raised px-4 py-3 text-sm text-ink-700">
                    <span>
                        This expense is {EXPENSE_STATUS_LABELS[expense.status] ?? expense.status} and can
                        no longer be edited here.
                    </span>
                    <Link
                        href={route('expenses.index')}
                        className="shrink-0 font-medium text-lagoon hover:underline"
                    >
                        Back to expenses &rarr;
                    </Link>
                </div>
            )}

            {isDraft && recordError && (
                <p role="alert" className="mb-4 rounded-md bg-clay-50 px-3 py-2 text-sm text-clay">
                    {recordError}
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
                                {isEditing && can['expenses.record'] && (
                                    <Button type="button" onClick={recordExpense} disabled={recording}>
                                        {recording ? 'Recording…' : 'Record expense'}
                                    </Button>
                                )}
                            </div>
                        ) : isRecorded && can['expenses.void'] ? (
                            <Button
                                type="button"
                                variant="danger"
                                onClick={() => setConfirmingVoid(true)}
                            >
                                Void expense
                            </Button>
                        ) : undefined
                    }
                >
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <Field label="Payee (optional)" htmlFor="expense-contact" error={errors.contact_id}>
                            <select
                                id="expense-contact"
                                value={form.data.contact_id}
                                disabled={!isDraft}
                                onChange={(event) => form.setData('contact_id', event.target.value)}
                                className={INPUT_CLASSES}
                            >
                                <option value="">No payee</option>
                                {contacts.map((contact) => (
                                    <option key={contact.id} value={contact.id}>
                                        {contact.name}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field
                            label="Paid from (optional)"
                            htmlFor="expense-bank-account"
                            error={errors.bank_account_id}
                        >
                            <select
                                id="expense-bank-account"
                                value={form.data.bank_account_id}
                                disabled={!isDraft}
                                onChange={(event) => form.setData('bank_account_id', event.target.value)}
                                className={INPUT_CLASSES}
                            >
                                <option value="">Not set</option>
                                {bankAccounts.map((bankAccount) => (
                                    <option key={bankAccount.id} value={bankAccount.id}>
                                        {bankAccount.name}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Expense date" htmlFor="expense-date" error={errors.expense_date}>
                            <input
                                id="expense-date"
                                type="date"
                                value={form.data.expense_date}
                                disabled={!isDraft}
                                onChange={(event) => form.setData('expense_date', event.target.value)}
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
                                    {expense!.lines.map((line) => (
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
                                minor={isDraft ? totals.subtotal : expense!.subtotal}
                                currency={currency}
                                size="sm"
                            />
                        </div>
                        <div className="flex w-full max-w-xs items-center justify-between text-sm text-ink-700">
                            <span>Tax</span>
                            <MoneyText
                                minor={isDraft ? totals.taxTotal : expense!.tax_total}
                                currency={currency}
                                size="sm"
                            />
                        </div>
                        <div className="flex w-full max-w-xs items-center justify-between text-base font-semibold text-ink">
                            <span>Total</span>
                            <MoneyText
                                minor={isDraft ? totals.total : expense!.total}
                                currency={currency}
                                size="md"
                            />
                        </div>
                    </div>

                    <Field label="Notes" htmlFor="expense-notes" error={errors.notes} className="mt-6">
                        <textarea
                            id="expense-notes"
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

            {confirmingDelete && (
                <DeleteConfirmDialog
                    onCancel={() => setConfirmingDelete(false)}
                    onConfirm={confirmDelete}
                    processing={deleting}
                />
            )}

            {confirmingVoid && (
                <VoidConfirmDialog
                    onCancel={() => setConfirmingVoid(false)}
                    onConfirm={confirmVoid}
                    processing={voiding}
                />
            )}
        </AppShell>
    );
}
