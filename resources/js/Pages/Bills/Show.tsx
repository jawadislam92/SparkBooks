import Button from '@/Components/ui/Button';
import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import AttachmentSection, { AttachmentData } from '@/Components/documents/AttachmentSection';
import BillPaymentModal, { BillPaymentModalBankAccount } from '@/Components/documents/BillPaymentModal';
import PostConfirmDialog from '@/Components/documents/PostConfirmDialog';
import StatusChip, { BILL_STATUS_LABELS } from '@/Components/documents/StatusChip';
import VoidDialog from '@/Components/documents/VoidDialog';
import AppShell from '@/Layouts/AppShell';
import { isBillOverdue, todayIsoDate } from '@/lib/overdue';
import { PageProps } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

interface BillShowLine {
    id: number;
    description: string;
    category_account: { id: number; code: string; name: string };
    tax_rate: { id: number; name: string } | null;
    /** Integer minor units, base currency (bills are base-currency-only in this phase). */
    amount: number;
    line_tax: number;
}

interface BillShowPayment {
    id: number;
    payment_date: string;
    amount: number;
    status: string;
    memo: string | null;
    bank_account: { id: number; name: string } | null;
}

interface BillShowData {
    id: number;
    status: string;
    is_opening: boolean;
    contact: { id: number; name: string };
    reference: string | null;
    bill_date: string | null;
    due_date: string | null;
    notes: string | null;
    subtotal: number;
    tax_total: number;
    total: number;
    amount_paid: number;
    balance: number;
    received_at: string | null;
    voided_at: string | null;
    lines: BillShowLine[];
    payments: BillShowPayment[];
    attachments: AttachmentData[];
}

interface ShowPageProps extends PageProps {
    bill: BillShowData;
    bankAccounts: BillPaymentModalBankAccount[];
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

export default function Show({ bill, bankAccounts }: ShowPageProps) {
    const { currentCompany, can, errors: pageErrors } = usePage<PageProps>().props;
    const currency = currentCompany?.base_currency ?? 'PKR';
    const isDraft = bill.status === 'draft';
    const canReceivePayment = bill.status === 'received' || bill.status === 'partially_paid';
    const isVoidable =
        !bill.is_opening && (bill.status === 'received' || bill.status === 'partially_paid' || bill.status === 'paid');
    const today = useMemo(() => todayIsoDate(), []);
    const overdue = isBillOverdue(bill, today);

    const [receiveDialogOpen, setReceiveDialogOpen] = useState(false);
    const [paymentModalOpen, setPaymentModalOpen] = useState(false);
    const [voidDialogOpen, setVoidDialogOpen] = useState(false);

    const errors = (pageErrors ?? {}) as Record<string, string | undefined>;

    return (
        <AppShell title="Bills">
            <Head title={bill.reference ?? `Bill #${bill.id}`} />

            <Link
                href={route('bills.index')}
                className="mb-4 inline-block text-sm font-medium text-lagoon hover:underline"
            >
                &larr; Back to bills
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
                                {bill.reference ?? `Bill #${bill.id}`}
                            </h2>
                            <StatusChip status={bill.status} labels={BILL_STATUS_LABELS} />
                            {overdue && (
                                <span className="inline-block rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-ink">
                                    Overdue
                                </span>
                            )}
                        </div>
                        <p className="mt-1 text-sm text-ink-700">{bill.contact.name}</p>
                        <div className="mt-3 flex flex-wrap gap-6 text-sm">
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-ink-700">
                                    Bill date
                                </p>
                                <p className="text-ink">{formatDate(bill.bill_date)}</p>
                            </div>
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-ink-700">
                                    Due date
                                </p>
                                <p className="text-ink">{formatDate(bill.due_date)}</p>
                            </div>
                            {bill.received_at && (
                                <div>
                                    <p className="text-xs font-medium uppercase tracking-wide text-ink-700">
                                        Received
                                    </p>
                                    <p className="text-ink">{formatDateTime(bill.received_at)}</p>
                                </div>
                            )}
                            {bill.voided_at && (
                                <div>
                                    <p className="text-xs font-medium uppercase tracking-wide text-ink-700">
                                        Voided
                                    </p>
                                    <p className="text-ink">{formatDateTime(bill.voided_at)}</p>
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="text-right">
                        <p className="text-xs font-medium uppercase tracking-wide text-ink-700">Total</p>
                        <MoneyText minor={bill.total} currency={currency} size="lg" />
                        <p className="mt-2 text-xs font-medium uppercase tracking-wide text-ink-700">
                            Balance
                        </p>
                        <MoneyText minor={bill.balance} currency={currency} size="md" />
                    </div>
                </div>

                {bill.is_opening && (
                    <p className="mt-4 rounded-md bg-paper px-3 py-2 text-sm text-ink-700">
                        Opening bill — manage via opening balances, not here. It can&apos;t be voided
                        from this screen.
                    </p>
                )}

                <div className="mt-6 flex flex-wrap items-center gap-2 border-t border-rule pt-4">
                    {isDraft && (
                        <Button
                            type="button"
                            variant="quiet"
                            onClick={() => router.visit(route('bills.edit', bill.id))}
                        >
                            Edit draft
                        </Button>
                    )}
                    {isDraft && can['bills.receive'] && (
                        <Button type="button" onClick={() => setReceiveDialogOpen(true)}>
                            Receive bill
                        </Button>
                    )}
                    {canReceivePayment && can['payments.record'] && (
                        <Button type="button" onClick={() => setPaymentModalOpen(true)}>
                            Record payment
                        </Button>
                    )}
                    {isVoidable && can['bills.void'] && (
                        <Button type="button" variant="danger" onClick={() => setVoidDialogOpen(true)}>
                            Void bill
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
                        {bill.lines.map((line) => (
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
                        <MoneyText minor={bill.subtotal} currency={currency} size="sm" />
                    </div>
                    <div className="flex w-full max-w-xs items-center justify-between text-sm text-ink-700">
                        <span>Tax</span>
                        <MoneyText minor={bill.tax_total} currency={currency} size="sm" />
                    </div>
                    <div className="flex w-full max-w-xs items-center justify-between text-base font-semibold text-ink">
                        <span>Total</span>
                        <MoneyText minor={bill.total} currency={currency} size="md" />
                    </div>
                    {!isDraft && (
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

                {bill.notes && (
                    <p className="mt-4 whitespace-pre-line border-t border-rule pt-4 text-sm text-ink-700">
                        {bill.notes}
                    </p>
                )}
            </Sheet>

            <Sheet title="Payments" className="mb-6">
                {bill.payments.length === 0 ? (
                    <p className="py-8 text-center text-sm text-ink-700">No payments recorded yet.</p>
                ) : (
                    <table className="w-full border-collapse text-left text-sm">
                        <thead>
                            <tr className="border-b border-rule text-xs font-medium uppercase tracking-wide text-ink-700">
                                <th scope="col" className="py-2 pr-3 font-medium">Date</th>
                                <th scope="col" className="py-2 pr-3 font-medium">Account</th>
                                <th scope="col" className="py-2 pr-3 font-medium">Status</th>
                                <th scope="col" className="py-2 pl-3 text-right font-medium">Amount</th>
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
                                    <td className="py-3 pl-3 align-top">
                                        <MoneyText minor={payment.amount} currency={currency} size="sm" />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Sheet>

            <Sheet>
                <AttachmentSection
                    attachments={bill.attachments}
                    uploadUrl={route('bills.attachments.store', bill.id)}
                    downloadUrl={(attachmentId) => route('bills.attachments.download', [bill.id, attachmentId])}
                    destroyUrl={(attachmentId) => route('bills.attachments.destroy', [bill.id, attachmentId])}
                    canUpload={can['bills.draft']}
                    canDelete={can['bills.void']}
                />
            </Sheet>

            {receiveDialogOpen && (
                <PostConfirmDialog
                    title="Receive this bill?"
                    description="Posts it to the ledger against accounts payable for this vendor. Once received, its lines can no longer be edited — correcting it means voiding and entering a new bill."
                    confirmLabel="Receive bill"
                    processingLabel="Receiving…"
                    postUrl={route('bills.receive', bill.id)}
                    subtotal={bill.subtotal}
                    taxTotal={bill.tax_total}
                    total={bill.total}
                    currency={currency}
                    onClose={() => setReceiveDialogOpen(false)}
                />
            )}

            {paymentModalOpen && (
                <BillPaymentModal
                    bill={{ id: bill.id, reference: bill.reference, balance: bill.balance }}
                    bankAccounts={bankAccounts}
                    currency={currency}
                    onClose={() => setPaymentModalOpen(false)}
                />
            )}

            {voidDialogOpen && (
                <VoidDialog
                    title={`Void bill ${bill.reference ?? `#${bill.id}`}`}
                    description="Posts a reversing entry that clears this vendor's outstanding balance on the books. The bill stays visible, marked Void, but this cannot be undone — to receive it again you'll need a new bill."
                    confirmLabel="Void bill"
                    processingLabel="Voiding…"
                    voidUrl={route('bills.void', bill.id)}
                    payments={bill.payments}
                    voidPaymentUrl={(paymentId) => route('bills.payments.void', [bill.id, paymentId])}
                    currency={currency}
                    onClose={() => setVoidDialogOpen(false)}
                />
            )}
        </AppShell>
    );
}
