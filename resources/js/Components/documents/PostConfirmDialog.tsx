import Button from '@/Components/ui/Button';
import MoneyText from '@/Components/ui/MoneyText';
import { useForm } from '@inertiajs/react';
import {
    Dialog,
    DialogBackdrop,
    DialogPanel,
    DialogTitle,
} from '@headlessui/react';
import { useState } from 'react';

interface PostConfirmDialogProps {
    title: string;
    description: string;
    confirmLabel: string;
    processingLabel: string;
    postUrl: string;
    subtotal: number;
    taxTotal: number;
    total: number;
    currency: string;
    onClose: () => void;
}

/**
 * The whole-document "post it" confirmation shared by Expenses/Show's
 * Record action and Bills/Show's Receive action (Phase 4 Task 9) — both a
 * one-way, ledger-posting step (P4/P5 and P6/P6t respectively, spec §6), so
 * each gets a confirm step showing the computed totals about to be posted
 * before the click actually fires, mirroring Invoices/Show's
 * SendInvoiceDialog in spirit (a confirm dialog for the analogous "post this
 * draft" action) though with no form fields of its own — record/receive
 * take no body data, just the idempotency key.
 */
export default function PostConfirmDialog({
    title,
    description,
    confirmLabel,
    processingLabel,
    postUrl,
    subtotal,
    taxTotal,
    total,
    currency,
    onClose,
}: PostConfirmDialogProps) {
    // Stable for this dialog's whole open session — a validation-error retry
    // reuses it; reopening the dialog is a fresh mount and gets a fresh one.
    // Mirrors PaymentModal/VoidDialog's identical per-open-key reasoning.
    const [idempotencyKey] = useState(() => crypto.randomUUID());
    const form = useForm({});
    const errors = form.errors as Record<string, string | undefined>;

    function confirm() {
        form.post(postUrl, {
            headers: { 'X-Idempotency-Key': idempotencyKey },
            preserveScroll: true,
            onSuccess: onClose,
        });
    }

    return (
        <Dialog open onClose={onClose} className="relative z-50">
            <DialogBackdrop className="fixed inset-0 bg-ink/30" />
            <div className="fixed inset-0 flex items-center justify-center p-4">
                <DialogPanel className="w-full max-w-sm rounded-sheet bg-paper-raised p-6 shadow-sheet">
                    <DialogTitle className="font-display text-lg font-semibold text-ink">
                        {title}
                    </DialogTitle>
                    <p className="mt-1 text-sm text-ink-700">{description}</p>

                    {errors.status && (
                        <p role="alert" className="mt-3 rounded-md bg-clay-50 px-3 py-2 text-sm text-clay">
                            {errors.status}
                        </p>
                    )}

                    <div className="mt-4 flex flex-col gap-1 border-t border-rule pt-4">
                        <div className="flex items-center justify-between text-sm text-ink-700">
                            <span>Subtotal</span>
                            <MoneyText minor={subtotal} currency={currency} size="sm" />
                        </div>
                        <div className="flex items-center justify-between text-sm text-ink-700">
                            <span>Tax</span>
                            <MoneyText minor={taxTotal} currency={currency} size="sm" />
                        </div>
                        <div className="flex items-center justify-between text-base font-semibold text-ink">
                            <span>Total</span>
                            <MoneyText minor={total} currency={currency} size="md" />
                        </div>
                    </div>

                    <div className="mt-5 flex justify-end gap-2">
                        <Button type="button" variant="quiet" onClick={onClose} disabled={form.processing}>
                            Cancel
                        </Button>
                        <Button type="button" onClick={confirm} disabled={form.processing}>
                            {form.processing ? processingLabel : confirmLabel}
                        </Button>
                    </div>
                </DialogPanel>
            </div>
        </Dialog>
    );
}
