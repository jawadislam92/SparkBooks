import Button from '@/Components/ui/Button';
import Field from '@/Components/ui/Field';
import MoneyText from '@/Components/ui/MoneyText';
import { router, useForm } from '@inertiajs/react';
import {
    Dialog,
    DialogBackdrop,
    DialogPanel,
    DialogTitle,
} from '@headlessui/react';
import { FormEventHandler, useState } from 'react';

export interface VoidDialogPayment {
    id: number;
    payment_date: string;
    /** Integer minor units, document currency. */
    amount: number;
    status: string;
    bank_account: { id: number; name: string } | null;
}

export interface VoidDialogInvoice {
    id: number;
    number: string | null;
    currency_code: string;
    payments: VoidDialogPayment[];
}

interface VoidFormData {
    date: string;
    memo: string;
}

function todayIsoDate(): string {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function formatDate(value: string): string {
    return new Date(`${value}T00:00:00`).toLocaleDateString('en-PK', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

const INPUT_CLASSES =
    'h-10 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark disabled:cursor-not-allowed disabled:opacity-60';

/**
 * The accounting-correct void flow (spec §6): a sent/partially-paid/paid
 * invoice can't be voided while it still carries a live payment — each must
 * be reversed one at a time first. This dialog stays open across each
 * payment reversal (`preserveState`, so Show's own `invoice` prop — and
 * therefore this component's own `invoice` prop — updates in place without
 * losing the dialog's local state) and only reveals the final "void the
 * invoice" form once no live payment remains.
 */
export default function VoidDialog({ invoice, onClose }: { invoice: VoidDialogInvoice; onClose: () => void }) {
    const livePayments = invoice.payments.filter((payment) => payment.status !== 'void');
    const [voidingPaymentId, setVoidingPaymentId] = useState<number | null>(null);

    // Stable for the dialog's whole lifetime — a validation-error retry of
    // the final void reuses it; reopening the dialog (a fresh mount) gets a
    // fresh one. Mirrors PaymentModal's per-open key.
    const [voidKey] = useState(() => crypto.randomUUID());

    const form = useForm<VoidFormData>({ date: todayIsoDate(), memo: '' });
    const errors = form.errors as Record<string, string | undefined>;

    function reversePayment(paymentId: number) {
        setVoidingPaymentId(paymentId);
        router.post(
            route('invoices.payments.void', [invoice.id, paymentId]),
            {},
            {
                headers: { 'X-Idempotency-Key': crypto.randomUUID() },
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setVoidingPaymentId(null),
            },
        );
    }

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.post(route('invoices.void', invoice.id), {
            headers: { 'X-Idempotency-Key': voidKey },
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open onClose={onClose} className="relative z-50">
            <DialogBackdrop className="fixed inset-0 bg-ink/30" />
            <div className="fixed inset-0 flex items-center justify-center p-4">
                <DialogPanel className="w-full max-w-md rounded-sheet bg-paper-raised p-6 shadow-sheet">
                    <DialogTitle className="font-display text-lg font-semibold text-ink">
                        Void invoice {invoice.number ?? ''}
                    </DialogTitle>

                    {livePayments.length > 0 ? (
                        <>
                            <p className="mt-2 text-sm text-ink-700">
                                Reverse the {livePayments.length} payment
                                {livePayments.length === 1 ? '' : 's'} on this invoice first — an invoice
                                can&apos;t be voided while a payment is still live.
                            </p>
                            <ul className="mt-4 flex flex-col gap-2">
                                {livePayments.map((payment) => (
                                    <li
                                        key={payment.id}
                                        className="flex items-center justify-between gap-3 rounded-md border border-rule px-3 py-2"
                                    >
                                        <div>
                                            <MoneyText
                                                minor={payment.amount}
                                                currency={invoice.currency_code}
                                                size="sm"
                                                className="text-left"
                                            />
                                            <p className="text-xs text-ink-700">
                                                {formatDate(payment.payment_date)} ·{' '}
                                                {payment.bank_account?.name ?? 'Unknown account'}
                                            </p>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="danger"
                                            size="sm"
                                            disabled={voidingPaymentId === payment.id}
                                            onClick={() => reversePayment(payment.id)}
                                        >
                                            {voidingPaymentId === payment.id ? 'Reversing…' : 'Reverse'}
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                            <div className="mt-5 flex justify-end">
                                <Button type="button" variant="quiet" onClick={onClose}>
                                    Close
                                </Button>
                            </div>
                        </>
                    ) : (
                        <form onSubmit={submit} className="mt-4 flex flex-col gap-4">
                            <p className="text-sm text-ink-700">
                                Posts a mirror reversal of this invoice&apos;s ledger entry. The original
                                entry stays on the books, unmodified.
                            </p>

                            {errors.status && (
                                <p role="alert" className="rounded-md bg-clay-50 px-3 py-2 text-sm text-clay">
                                    {errors.status}
                                </p>
                            )}

                            <Field label="Reversal date" htmlFor="void-date" error={errors.date}>
                                <input
                                    id="void-date"
                                    type="date"
                                    value={form.data.date}
                                    onChange={(event) => form.setData('date', event.target.value)}
                                    className={INPUT_CLASSES}
                                />
                            </Field>
                            <Field label="Memo" htmlFor="void-memo" error={errors.memo}>
                                <input
                                    id="void-memo"
                                    type="text"
                                    value={form.data.memo}
                                    onChange={(event) => form.setData('memo', event.target.value)}
                                    placeholder="Why is this being voided?"
                                    className={INPUT_CLASSES}
                                />
                            </Field>
                            <div className="mt-2 flex justify-end gap-2">
                                <Button type="button" variant="quiet" onClick={onClose} disabled={form.processing}>
                                    Cancel
                                </Button>
                                <Button type="submit" variant="danger" disabled={form.processing}>
                                    {form.processing ? 'Voiding…' : 'Void invoice'}
                                </Button>
                            </div>
                        </form>
                    )}
                </DialogPanel>
            </div>
        </Dialog>
    );
}
