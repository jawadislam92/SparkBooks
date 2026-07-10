import Button from '@/Components/ui/Button';
import MoneyText from '@/Components/ui/MoneyText';
import { router, useForm } from '@inertiajs/react';
import {
    Dialog,
    DialogBackdrop,
    DialogPanel,
    DialogTitle,
} from '@headlessui/react';
import { useState } from 'react';

export interface VoidDialogPayment {
    id: number;
    payment_date: string;
    /** Integer minor units, document currency. */
    amount: number;
    status: string;
    bank_account: { id: number; name: string } | null;
}

function formatDate(value: string): string {
    return new Date(`${value}T00:00:00`).toLocaleDateString('en-PK', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

interface VoidDialogProps {
    title: string;
    /** Plain-language explanation of what voiding posts — shown once no live payment remains (or immediately, for a document with no payments concept). */
    description: string;
    confirmLabel: string;
    processingLabel: string;
    voidUrl: string;
    onClose: () => void;
    /** Bills only — Expenses have no separate payments concept (single-step record/void), so this is simply omitted there. */
    payments?: VoidDialogPayment[];
    voidPaymentUrl?: (paymentId: number) => string;
    /** Required whenever `payments` is passed, to render each payment's MoneyText. */
    currency?: string;
}

/**
 * The whole-document void confirmation shared by Expenses/Show and
 * Bills/Show (Phase 4 Task 9) — the accounting-correct flow (spec §6),
 * mirroring Components/invoices/VoidDialog's "reverse every live payment
 * first" UX exactly, generalized over documents that may or may not carry a
 * payments concept: Expense has none, so `payments` is omitted and this
 * renders straight to the plain confirm step; Bill carries the full
 * live-payments-first flow (this dialog stays open across each payment
 * reversal via `preserveState`, so the caller's own `payments` prop updates
 * in place without losing this component's local state — mirrors
 * invoices/VoidDialog's identical reasoning).
 *
 * Unlike invoices/VoidDialog, the final confirm step here is a bare
 * Cancel/Void pair with no date/memo fields — neither
 * ExpenseController::void nor BillController::void/voidPayment accept a
 * date/memo body; the server always reverses dated today with a generic
 * memo (see each controller's own docblock), so there's nothing for a form
 * to collect.
 */
export default function VoidDialog({
    title,
    description,
    confirmLabel,
    processingLabel,
    voidUrl,
    onClose,
    payments = [],
    voidPaymentUrl,
    currency = '',
}: VoidDialogProps) {
    const livePayments = payments.filter((payment) => payment.status !== 'void');
    const [voidingPaymentId, setVoidingPaymentId] = useState<number | null>(null);

    // Stable for the dialog's whole lifetime — a validation-error retry of
    // the final void reuses it; reopening the dialog (a fresh mount) gets a
    // fresh one. Mirrors invoices/VoidDialog's identical reasoning.
    const form = useForm({});
    const errors = form.errors as Record<string, string | undefined>;

    function reversePayment(paymentId: number) {
        if (!voidPaymentUrl) {
            return;
        }
        setVoidingPaymentId(paymentId);
        router.post(
            voidPaymentUrl(paymentId),
            {},
            {
                headers: { 'X-Idempotency-Key': crypto.randomUUID() },
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setVoidingPaymentId(null),
            },
        );
    }

    function confirmVoid() {
        form.post(voidUrl, {
            headers: { 'X-Idempotency-Key': crypto.randomUUID() },
            preserveScroll: true,
            onSuccess: onClose,
        });
    }

    return (
        <Dialog open onClose={onClose} className="relative z-50">
            <DialogBackdrop className="fixed inset-0 bg-ink/30" />
            <div className="fixed inset-0 flex items-center justify-center p-4">
                <DialogPanel className="w-full max-w-md rounded-sheet bg-paper-raised p-6 shadow-sheet">
                    <DialogTitle className="font-display text-lg font-semibold text-ink">
                        {title}
                    </DialogTitle>

                    {livePayments.length > 0 ? (
                        <>
                            <p className="mt-2 text-sm text-ink-700">
                                Reverse the {livePayments.length} payment
                                {livePayments.length === 1 ? '' : 's'} on this bill first — it
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
                                                currency={currency}
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
                        <>
                            <p className="mt-2 text-sm text-ink-700">{description}</p>

                            {errors.status && (
                                <p role="alert" className="mt-3 rounded-md bg-clay-50 px-3 py-2 text-sm text-clay">
                                    {errors.status}
                                </p>
                            )}

                            <div className="mt-5 flex justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="quiet"
                                    onClick={onClose}
                                    disabled={form.processing}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="button"
                                    variant="danger"
                                    onClick={confirmVoid}
                                    disabled={form.processing}
                                >
                                    {form.processing ? processingLabel : confirmLabel}
                                </Button>
                            </div>
                        </>
                    )}
                </DialogPanel>
            </div>
        </Dialog>
    );
}
