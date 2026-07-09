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
import { FormEventHandler, useMemo, useState } from 'react';

export interface PaymentModalInvoice {
    id: number;
    number: string | null;
    currency_code: string;
    /** Integer minor units, document currency. */
    balance: number;
}

export interface PaymentModalBankAccount {
    id: number;
    name: string;
    currency_code: string;
}

interface PaymentFormData {
    amountText: string;
    baseAmountText: string;
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

interface PaymentModalProps {
    invoice: PaymentModalInvoice;
    bankAccounts: PaymentModalBankAccount[];
    /** The company's base currency — the invoice is "foreign" when its own currency differs from this. */
    baseCurrency: string;
    onClose: () => void;
}

/**
 * Records a payment against a sent/partially-paid invoice (posting rules
 * P2/P3, extended P2fx for a foreign-currency invoice — spec §17.1). The
 * "actual base amount received" field only appears for a foreign invoice —
 * it's a genuinely independent, user-entered figure (what the bank actually
 * credited), never derived from the invoice's own fx_rate; the implied rate
 * shown below is display-only, mirroring InvoicePaymentService::impliedRate.
 */
export default function PaymentModal({ invoice, bankAccounts, baseCurrency, onClose }: PaymentModalProps) {
    const isForeign = invoice.currency_code !== baseCurrency;

    // One idempotency key per modal-open — stable across a validation-error
    // retry (e.g. overpayment), so a resubmit of the same intent never
    // double-posts. Mirrors Journal/New's stable per-form-open key.
    const [idempotencyKey] = useState(() => crypto.randomUUID());

    const form = useForm<PaymentFormData>({
        amountText: minorToDecimalString(invoice.balance),
        baseAmountText: '',
        bank_account_id: bankAccounts.length === 1 ? String(bankAccounts[0].id) : '',
        payment_date: todayIsoDate(),
        memo: '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const impliedRate = useMemo(() => {
        const amount = parseMoneyInput(form.data.amountText);
        const baseAmount = parseMoneyInput(form.data.baseAmountText);
        if (!amount || !baseAmount) {
            return null;
        }
        return (baseAmount / amount).toFixed(4);
    }, [form.data.amountText, form.data.baseAmountText]);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.transform((data) => ({
            amount: parseMoneyInput(data.amountText) ?? 0,
            base_amount: isForeign ? parseMoneyInput(data.baseAmountText) : undefined,
            bank_account_id: data.bank_account_id === '' ? null : Number(data.bank_account_id),
            payment_date: data.payment_date,
            memo: data.memo.trim() === '' ? null : data.memo.trim(),
        }));

        form.post(route('invoices.payments.record', invoice.id), {
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
                        Against invoice {invoice.number ?? 'Draft'}.
                    </p>

                    {errors.status && (
                        <p role="alert" className="mt-3 rounded-md bg-clay-50 px-3 py-2 text-sm text-clay">
                            {errors.status}
                        </p>
                    )}

                    <form onSubmit={submit} className="mt-4 flex flex-col gap-4">
                        <Field
                            label={`Amount (${invoice.currency_code})`}
                            htmlFor="payment-amount"
                            error={errors.amount}
                        >
                            <input
                                id="payment-amount"
                                type="text"
                                inputMode="decimal"
                                value={form.data.amountText}
                                onChange={(event) => form.setData('amountText', event.target.value)}
                                className={`${INPUT_CLASSES} text-right font-mono tabular-nums`}
                            />
                        </Field>

                        {isForeign && (
                            <Field
                                label={`Amount received in ${baseCurrency} (base)`}
                                htmlFor="payment-base-amount"
                                error={errors.base_amount}
                            >
                                <input
                                    id="payment-base-amount"
                                    type="text"
                                    inputMode="decimal"
                                    placeholder="0.00"
                                    value={form.data.baseAmountText}
                                    onChange={(event) => form.setData('baseAmountText', event.target.value)}
                                    className={`${INPUT_CLASSES} text-right font-mono tabular-nums`}
                                />
                                <p className="text-xs text-ink-700">
                                    The actual {baseCurrency} the bank credited — may differ from the
                                    invoice&apos;s own rate.
                                    {impliedRate && ` Implied rate: ${impliedRate}.`}
                                </p>
                            </Field>
                        )}

                        <Field
                            label="Bank / cash account"
                            htmlFor="payment-bank-account"
                            error={errors.bank_account_id}
                        >
                            <select
                                id="payment-bank-account"
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

                        <Field label="Payment date" htmlFor="payment-date" error={errors.payment_date}>
                            <input
                                id="payment-date"
                                type="date"
                                value={form.data.payment_date}
                                onChange={(event) => form.setData('payment_date', event.target.value)}
                                className={INPUT_CLASSES}
                            />
                        </Field>

                        <Field label="Memo" htmlFor="payment-memo" error={errors.memo}>
                            <input
                                id="payment-memo"
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
