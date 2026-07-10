import Button from '@/Components/ui/Button';
import Field from '@/Components/ui/Field';
import { minorToDecimalString, parseMoneyInput } from '@/lib/money';
import { useForm } from '@inertiajs/react';
import {
    Dialog,
    DialogBackdrop,
    DialogPanel,
    DialogTitle,
} from '@headlessui/react';
import { FormEventHandler, useState } from 'react';

export interface BillPaymentModalBill {
    id: number;
    reference: string | null;
    /** Integer minor units, base currency (bills are base-currency-only in this phase). */
    balance: number;
}

export interface BillPaymentModalBankAccount {
    id: number;
    name: string;
}

interface PaymentFormData {
    amountText: string;
    bank_account_id: string;
    payment_date: string;
    memo: string;
}

function todayIsoDate(): string {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

const INPUT_CLASSES =
    'h-10 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark disabled:cursor-not-allowed disabled:opacity-60';

interface BillPaymentModalProps {
    bill: BillPaymentModalBill;
    bankAccounts: BillPaymentModalBankAccount[];
    currency: string;
    onClose: () => void;
}

/**
 * Records a payment against a received/partially-paid bill (posting rule P7,
 * spec §6): Dr 2000 Accounts Payable / Cr the paying bank account's linked
 * ledger account. Minimal functional form ahead of the full Task 9 UI —
 * mirrors Components/invoices/PaymentModal, minus the P2fx "actual base
 * amount received" leg: bills are base-currency-only in this phase (Phase 4
 * brief), so there's nothing foreign to reconcile.
 */
export default function BillPaymentModal({ bill, bankAccounts, currency, onClose }: BillPaymentModalProps) {
    // One idempotency key per modal-open — stable across a validation-error
    // retry (e.g. overpayment), so a resubmit of the same intent never
    // double-posts. Mirrors invoices/PaymentModal.
    const [idempotencyKey] = useState(() => crypto.randomUUID());

    const form = useForm<PaymentFormData>({
        amountText: minorToDecimalString(bill.balance),
        bank_account_id: bankAccounts.length === 1 ? String(bankAccounts[0].id) : '',
        payment_date: todayIsoDate(),
        memo: '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.transform((data) => ({
            amount: parseMoneyInput(data.amountText) ?? 0,
            bank_account_id: data.bank_account_id === '' ? null : Number(data.bank_account_id),
            payment_date: data.payment_date,
            memo: data.memo.trim() === '' ? null : data.memo.trim(),
        }));

        form.post(route('bills.payments.record', bill.id), {
            headers: { 'X-Idempotency-Key': idempotencyKey },
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
                        Record payment
                    </DialogTitle>
                    <p className="mt-1 text-sm text-ink-700">
                        Against bill {bill.reference ?? `#${bill.id}`}.
                    </p>

                    {errors.status && (
                        <p role="alert" className="mt-3 rounded-md bg-clay-50 px-3 py-2 text-sm text-clay">
                            {errors.status}
                        </p>
                    )}

                    <form onSubmit={submit} className="mt-4 flex flex-col gap-4">
                        <Field
                            label={`Amount (${currency})`}
                            htmlFor="bill-payment-amount"
                            error={errors.amount}
                        >
                            <input
                                id="bill-payment-amount"
                                type="text"
                                inputMode="decimal"
                                value={form.data.amountText}
                                onChange={(event) => form.setData('amountText', event.target.value)}
                                className={`${INPUT_CLASSES} text-right font-mono tabular-nums`}
                            />
                        </Field>

                        <Field
                            label="Bank / cash account"
                            htmlFor="bill-payment-bank-account"
                            error={errors.bank_account_id}
                        >
                            <select
                                id="bill-payment-bank-account"
                                value={form.data.bank_account_id}
                                onChange={(event) => form.setData('bank_account_id', event.target.value)}
                                className={INPUT_CLASSES}
                            >
                                <option value="">Select an account</option>
                                {bankAccounts.map((account) => (
                                    <option key={account.id} value={account.id}>
                                        {account.name}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Payment date" htmlFor="bill-payment-date" error={errors.payment_date}>
                            <input
                                id="bill-payment-date"
                                type="date"
                                value={form.data.payment_date}
                                onChange={(event) => form.setData('payment_date', event.target.value)}
                                className={INPUT_CLASSES}
                            />
                        </Field>

                        <Field label="Memo" htmlFor="bill-payment-memo" error={errors.memo}>
                            <input
                                id="bill-payment-memo"
                                type="text"
                                value={form.data.memo}
                                onChange={(event) => form.setData('memo', event.target.value)}
                                placeholder="Optional note"
                                className={INPUT_CLASSES}
                            />
                        </Field>

                        <div className="mt-2 flex justify-end gap-2">
                            <Button type="button" variant="quiet" onClick={onClose} disabled={form.processing}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing ? 'Recording…' : 'Record payment'}
                            </Button>
                        </div>
                    </form>
                </DialogPanel>
            </div>
        </Dialog>
    );
}
