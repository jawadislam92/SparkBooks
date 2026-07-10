import Button from '@/Components/ui/Button';
import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import AttachmentSection, { AttachmentData } from '@/Components/documents/AttachmentSection';
import PostConfirmDialog from '@/Components/documents/PostConfirmDialog';
import StatusChip, { EXPENSE_STATUS_LABELS } from '@/Components/documents/StatusChip';
import VoidDialog from '@/Components/documents/VoidDialog';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface ExpenseShowLine {
    id: number;
    description: string;
    category_account: { id: number; code: string; name: string };
    tax_rate: { id: number; name: string } | null;
    /** Integer minor units, document currency. */
    amount: number;
    line_tax: number;
}

interface ExpenseShowData {
    id: number;
    status: string;
    contact: { id: number; name: string } | null;
    expense_date: string | null;
    bank_account: { id: number; name: string } | null;
    notes: string | null;
    subtotal: number;
    tax_total: number;
    total: number;
    recorded_at: string | null;
    voided_at: string | null;
    lines: ExpenseShowLine[];
    attachments: AttachmentData[];
}

interface ShowPageProps extends PageProps {
    expense: ExpenseShowData;
}

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

function formatDateTime(value: string | null): string {
    if (!value) {
        return '—';
    }
    return new Date(value).toLocaleString('en-PK', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

export default function Show({ expense }: ShowPageProps) {
    const { currentCompany, can, errors: pageErrors } = usePage<PageProps>().props;
    const currency = currentCompany?.base_currency ?? 'PKR';
    const isDraft = expense.status === 'draft';
    const isRecorded = expense.status === 'recorded';

    const [recordDialogOpen, setRecordDialogOpen] = useState(false);
    const [voidDialogOpen, setVoidDialogOpen] = useState(false);

    const errors = (pageErrors ?? {}) as Record<string, string | undefined>;

    return (
        <AppShell title="Expenses">
            <Head title={expense.contact?.name ? `Expense — ${expense.contact.name}` : 'Expense'} />

            <Link
                href={route('expenses.index')}
                className="mb-4 inline-block text-sm font-medium text-lagoon hover:underline"
            >
                &larr; Back to expenses
            </Link>

            {errors.status && (
                <div role="alert" className="mb-4 rounded-md bg-clay-50 px-4 py-3 text-sm text-clay">
                    {errors.status}
                </div>
            )}

            <Sheet className="mb-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="font-mono text-2xl font-semibold text-ink">
                                {formatDate(expense.expense_date)}
                            </h2>
                            <StatusChip status={expense.status} labels={EXPENSE_STATUS_LABELS} />
                        </div>
                        <p className="mt-1 text-sm text-ink-700">
                            {expense.contact?.name ?? 'No payee'}
                        </p>
                        <div className="mt-3 flex flex-wrap gap-6 text-sm">
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-ink-700">
                                    Paid from
                                </p>
                                <p className="text-ink">{expense.bank_account?.name ?? 'Not set'}</p>
                            </div>
                            {expense.recorded_at && (
                                <div>
                                    <p className="text-xs font-medium uppercase tracking-wide text-ink-700">
                                        Recorded
                                    </p>
                                    <p className="text-ink">{formatDateTime(expense.recorded_at)}</p>
                                </div>
                            )}
                            {expense.voided_at && (
                                <div>
                                    <p className="text-xs font-medium uppercase tracking-wide text-ink-700">
                                        Voided
                                    </p>
                                    <p className="text-ink">{formatDateTime(expense.voided_at)}</p>
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="text-right">
                        <p className="text-xs font-medium uppercase tracking-wide text-ink-700">Total</p>
                        <MoneyText minor={expense.total} currency={currency} size="lg" />
                    </div>
                </div>

                <div className="mt-6 flex flex-wrap items-center gap-2 border-t border-rule pt-4">
                    {isDraft && (
                        <Button
                            type="button"
                            variant="quiet"
                            onClick={() => router.visit(route('expenses.edit', expense.id))}
                        >
                            Edit draft
                        </Button>
                    )}
                    {isDraft && can['expenses.record'] && (
                        <Button type="button" onClick={() => setRecordDialogOpen(true)}>
                            Record expense
                        </Button>
                    )}
                    {isRecorded && can['expenses.void'] && (
                        <Button type="button" variant="danger" onClick={() => setVoidDialogOpen(true)}>
                            Void expense
                        </Button>
                    )}
                </div>
            </Sheet>

            <Sheet title="Line items" className="mb-6">
                <table className="w-full border-collapse text-left text-sm">
                    <thead>
                        <tr className="border-b border-rule text-xs font-medium uppercase tracking-wide text-ink-700">
                            <th scope="col" className="py-2 pr-3 font-medium">Description</th>
                            <th scope="col" className="py-2 pr-3 font-medium">Category</th>
                            <th scope="col" className="py-2 pr-3 text-right font-medium">Amount</th>
                            <th scope="col" className="py-2 pr-3 text-right font-medium">Tax</th>
                            <th scope="col" className="py-2 pl-3 text-right font-medium">Line total</th>
                        </tr>
                    </thead>
                    <tbody>
                        {expense.lines.map((line) => (
                            <tr key={line.id} className="border-b border-rule last:border-b-0">
                                <td className="py-3 pr-3">{line.description}</td>
                                <td className="py-3 pr-3 text-ink-700">
                                    {line.category_account.code} — {line.category_account.name}
                                </td>
                                <td className="py-3 pr-3">
                                    <MoneyText minor={line.amount} currency={currency} size="sm" />
                                </td>
                                <td className="py-3 pr-3">
                                    <MoneyText minor={line.line_tax} currency={currency} size="sm" />
                                </td>
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

                <div className="mt-4 flex flex-col items-end gap-1 border-t border-rule pt-4">
                    <div className="flex w-full max-w-xs items-center justify-between text-sm text-ink-700">
                        <span>Subtotal</span>
                        <MoneyText minor={expense.subtotal} currency={currency} size="sm" />
                    </div>
                    <div className="flex w-full max-w-xs items-center justify-between text-sm text-ink-700">
                        <span>Tax</span>
                        <MoneyText minor={expense.tax_total} currency={currency} size="sm" />
                    </div>
                    <div className="flex w-full max-w-xs items-center justify-between text-base font-semibold text-ink">
                        <span>Total</span>
                        <MoneyText minor={expense.total} currency={currency} size="md" />
                    </div>
                </div>

                {expense.notes && (
                    <p className="mt-4 whitespace-pre-line border-t border-rule pt-4 text-sm text-ink-700">
                        {expense.notes}
                    </p>
                )}
            </Sheet>

            <Sheet>
                <AttachmentSection
                    attachments={expense.attachments}
                    uploadUrl={route('expenses.attachments.store', expense.id)}
                    downloadUrl={(attachmentId) => route('expenses.attachments.download', [expense.id, attachmentId])}
                    destroyUrl={(attachmentId) => route('expenses.attachments.destroy', [expense.id, attachmentId])}
                    canUpload={can['expenses.draft']}
                    canDelete={can['expenses.void']}
                />
            </Sheet>

            {recordDialogOpen && (
                <PostConfirmDialog
                    title="Record this expense?"
                    description="Posts it to the ledger against the paid-from account. Once recorded, its lines can no longer be edited — correcting it means voiding and entering a new expense."
                    confirmLabel="Record expense"
                    processingLabel="Recording…"
                    postUrl={route('expenses.record', expense.id)}
                    subtotal={expense.subtotal}
                    taxTotal={expense.tax_total}
                    total={expense.total}
                    currency={currency}
                    onClose={() => setRecordDialogOpen(false)}
                />
            )}

            {voidDialogOpen && (
                <VoidDialog
                    title="Void this expense?"
                    description="Posts a reversing entry that undoes the expense on the books. The expense stays visible, marked Void, but this cannot be undone — to record it again you'll need a new expense."
                    confirmLabel="Void expense"
                    processingLabel="Voiding…"
                    voidUrl={route('expenses.void', expense.id)}
                    onClose={() => setVoidDialogOpen(false)}
                />
            )}
        </AppShell>
    );
}
