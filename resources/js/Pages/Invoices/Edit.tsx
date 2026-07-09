import Button from '@/Components/ui/Button';
import Field from '@/Components/ui/Field';
import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import InvoiceLineEditor, {
    IncomeAccountOption,
    ItemOption,
    TaxRateOption,
    InvoiceLineRow,
    buildInvoiceLinePayload,
    computeInvoiceTotals,
    emptyInvoiceLineRow,
} from '@/Components/invoices/InvoiceLineEditor';
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

interface InvoiceLineData {
    id: number;
    description: string;
    quantity: string;
    unit_price: number;
    income_account_id: number | null;
    tax_rate_id: number | null;
    line_subtotal: number;
    line_tax: number;
}

interface InvoiceData {
    id: number;
    number: string | null;
    status: string;
    contact_id: number;
    currency_code: string;
    issue_date: string | null;
    due_date: string | null;
    notes: string | null;
    subtotal: number;
    tax_total: number;
    total: number;
    lines: InvoiceLineData[];
}

interface EditPageProps extends PageProps {
    invoice: InvoiceData | null;
    contacts: ContactOption[];
    items: ItemOption[];
    incomeAccounts: IncomeAccountOption[];
    taxRates: TaxRateOption[];
    currencies: string[];
}

const INPUT_CLASSES =
    'h-10 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark disabled:cursor-not-allowed disabled:opacity-60';

const STATUS_LABELS: Record<string, string> = {
    draft: 'Draft',
    sent: 'Sent',
    partially_paid: 'Partially paid',
    paid: 'Paid',
    void: 'Void',
};

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
                        This invoice draft will be permanently deleted. This cannot be undone.
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

interface InvoiceFormData {
    contact_id: string;
    currency_code: string;
    issue_date: string;
    due_date: string;
    notes: string;
}

export default function Edit({ invoice, contacts, items, incomeAccounts, taxRates, currencies }: EditPageProps) {
    const { currentCompany } = usePage<PageProps>().props;
    const isEditing = invoice !== null;
    const isDraft = invoice === null || invoice.status === 'draft';
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);

    const [rows, setRows] = useState<InvoiceLineRow[]>(() =>
        invoice && invoice.lines.length > 0
            ? invoice.lines.map((line) => ({
                  key: crypto.randomUUID(),
                  itemId: null,
                  description: line.description,
                  quantityText: line.quantity,
                  unitPriceText: minorToDecimalString(line.unit_price),
                  incomeAccountId: line.income_account_id,
                  taxRateId: line.tax_rate_id,
              }))
            : [emptyInvoiceLineRow()],
    );

    const form = useForm<InvoiceFormData>({
        contact_id: invoice ? String(invoice.contact_id) : '',
        currency_code: invoice?.currency_code ?? currentCompany?.base_currency ?? 'PKR',
        issue_date: invoice?.issue_date ?? '',
        due_date: invoice?.due_date ?? '',
        notes: invoice?.notes ?? '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const totals = useMemo(() => computeInvoiceTotals(rows, taxRates), [rows, taxRates]);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.transform((data) => ({
            contact_id: data.contact_id === '' ? null : Number(data.contact_id),
            currency_code: data.currency_code,
            issue_date: data.issue_date === '' ? null : data.issue_date,
            due_date: data.due_date === '' ? null : data.due_date,
            notes: data.notes === '' ? null : data.notes,
            lines: buildInvoiceLinePayload(rows),
        }));

        if (isEditing) {
            form.patch(route('invoices.update', invoice!.id), { preserveScroll: true });
        } else {
            form.post(route('invoices.store'), { preserveScroll: true });
        }
    };

    function confirmDelete() {
        if (!invoice) {
            return;
        }
        setDeleting(true);
        router.delete(route('invoices.destroy', invoice.id), {
            onFinish: () => setDeleting(false),
        });
    }

    const pageTitle = isEditing ? (invoice!.number ?? 'Draft invoice') : 'New invoice';

    return (
        <AppShell title="Invoices">
            <Head title={pageTitle} />

            {!isDraft && invoice && (
                <div className="mb-4 flex items-center justify-between gap-4 rounded-md border border-rule bg-paper-raised px-4 py-3 text-sm text-ink-700">
                    <span>
                        This invoice is {STATUS_LABELS[invoice.status] ?? invoice.status} and can no
                        longer be edited here.
                    </span>
                    <Link
                        href={route('invoices.show', invoice.id)}
                        className="shrink-0 font-medium text-lagoon hover:underline"
                    >
                        View invoice &rarr;
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
                        <Field label="Customer" htmlFor="invoice-contact" error={errors.contact_id}>
                            <select
                                id="invoice-contact"
                                value={form.data.contact_id}
                                disabled={!isDraft}
                                onChange={(event) => form.setData('contact_id', event.target.value)}
                                className={INPUT_CLASSES}
                            >
                                <option value="">Select a customer</option>
                                {contacts.map((contact) => (
                                    <option key={contact.id} value={contact.id}>
                                        {contact.name}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Currency" htmlFor="invoice-currency" error={errors.currency_code}>
                            <select
                                id="invoice-currency"
                                value={form.data.currency_code}
                                disabled={!isDraft}
                                onChange={(event) => form.setData('currency_code', event.target.value)}
                                className={INPUT_CLASSES}
                            >
                                {currencies.map((code) => (
                                    <option key={code} value={code}>
                                        {code}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Issue date" htmlFor="invoice-issue-date" error={errors.issue_date}>
                            <input
                                id="invoice-issue-date"
                                type="date"
                                value={form.data.issue_date}
                                disabled={!isDraft}
                                onChange={(event) => form.setData('issue_date', event.target.value)}
                                className={INPUT_CLASSES}
                            />
                        </Field>

                        <Field label="Due date" htmlFor="invoice-due-date" error={errors.due_date}>
                            <input
                                id="invoice-due-date"
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
                            <InvoiceLineEditor
                                rows={rows}
                                items={items}
                                incomeAccounts={incomeAccounts}
                                taxRates={taxRates}
                                currency={form.data.currency_code}
                                onChange={setRows}
                                errors={errors}
                            />
                        ) : (
                            <table className="w-full border-collapse text-left text-sm">
                                <thead>
                                    <tr className="border-b border-rule text-xs font-medium uppercase tracking-wide text-ink-700">
                                        <th className="py-2 pr-3 font-medium">Description</th>
                                        <th className="py-2 pr-3 text-right font-medium">Qty</th>
                                        <th className="py-2 pl-3 text-right font-medium">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {invoice!.lines.map((line) => (
                                        <tr key={line.id} className="border-b border-rule last:border-b-0">
                                            <td className="py-3 pr-3">{line.description}</td>
                                            <td className="py-3 pr-3 text-right font-mono tabular-nums">
                                                {line.quantity}
                                            </td>
                                            <td className="py-3 pl-3">
                                                <MoneyText
                                                    minor={line.line_subtotal + line.line_tax}
                                                    currency={invoice!.currency_code}
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
                                minor={isDraft ? totals.subtotal : invoice!.subtotal}
                                currency={form.data.currency_code}
                                size="sm"
                            />
                        </div>
                        <div className="flex w-full max-w-xs items-center justify-between text-sm text-ink-700">
                            <span>Tax</span>
                            <MoneyText
                                minor={isDraft ? totals.taxTotal : invoice!.tax_total}
                                currency={form.data.currency_code}
                                size="sm"
                            />
                        </div>
                        <div className="flex w-full max-w-xs items-center justify-between text-base font-semibold text-ink">
                            <span>Total</span>
                            <MoneyText
                                minor={isDraft ? totals.total : invoice!.total}
                                currency={form.data.currency_code}
                                size="md"
                            />
                        </div>
                    </div>

                    <Field label="Notes" htmlFor="invoice-notes" error={errors.notes} className="mt-6">
                        <textarea
                            id="invoice-notes"
                            value={form.data.notes}
                            disabled={!isDraft}
                            onChange={(event) => form.setData('notes', event.target.value)}
                            rows={3}
                            placeholder="Payment instructions or thank-you note"
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
        </AppShell>
    );
}
