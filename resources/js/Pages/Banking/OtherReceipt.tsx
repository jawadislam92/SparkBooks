import Button from '@/Components/ui/Button';
import Field from '@/Components/ui/Field';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { parseMoneyInput } from '@/lib/money';
import { PageProps } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface BankAccountOption {
    id: number;
    name: string;
    currency_code: string;
    balance: number;
}

interface CategoryAccountOption {
    id: number;
    code: string;
    name: string;
    type: string;
}

interface OtherReceiptPageProps extends PageProps {
    bankAccounts: BankAccountOption[];
    categoryAccounts: CategoryAccountOption[];
}

const INPUT_CLASSES =
    'h-10 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

function todayIsoDate(): string {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

interface OtherReceiptFormData {
    bank_account_id: string;
    category_account_id: string;
    receipt_date: string;
    amount: string;
    payer: string;
    memo: string;
}

export default function OtherReceipt({ bankAccounts, categoryAccounts }: OtherReceiptPageProps) {
    // One idempotency key per page-open — stable across retries after a
    // validation error, so a resubmit of the same intent never double-posts.
    const [idempotencyKey] = useState(() => crypto.randomUUID());

    const form = useForm<OtherReceiptFormData>({
        bank_account_id: '',
        category_account_id: '',
        receipt_date: todayIsoDate(),
        amount: '',
        payer: '',
        memo: '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        const minorAmount = parseMoneyInput(form.data.amount);
        if (minorAmount === null || minorAmount <= 0) {
            form.setError('amount', 'Enter an amount greater than zero.');
            return;
        }

        form.transform((data) => ({
            bank_account_id: data.bank_account_id === '' ? null : Number(data.bank_account_id),
            category_account_id:
                data.category_account_id === '' ? null : Number(data.category_account_id),
            receipt_date: data.receipt_date,
            amount: minorAmount,
            payer: data.payer === '' ? null : data.payer,
            memo: data.memo === '' ? null : data.memo,
        }));

        form.post(route('other-receipts.store'), {
            headers: { 'X-Idempotency-Key': idempotencyKey },
            preserveScroll: true,
        });
    };

    return (
        <AppShell title="Record a receipt">
            <Head title="Record a receipt" />

            <Sheet title="Money in that isn't an invoice payment">
                <form onSubmit={submit} className="flex max-w-lg flex-col gap-4">
                    {errors.idempotency_key && (
                        <p
                            role="alert"
                            className="rounded-md bg-clay-50 px-3 py-2 text-sm text-clay"
                        >
                            {errors.idempotency_key}
                        </p>
                    )}

                    <Field
                        label="Deposit to"
                        htmlFor="other-receipt-bank"
                        error={errors.bank_account_id}
                    >
                        <select
                            id="other-receipt-bank"
                            value={form.data.bank_account_id}
                            onChange={(event) =>
                                form.setData('bank_account_id', event.target.value)
                            }
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

                    <Field
                        label="Category"
                        htmlFor="other-receipt-category"
                        error={errors.category_account_id}
                    >
                        <select
                            id="other-receipt-category"
                            value={form.data.category_account_id}
                            onChange={(event) =>
                                form.setData('category_account_id', event.target.value)
                            }
                            className={INPUT_CLASSES}
                        >
                            <option value="">Select a category</option>
                            {categoryAccounts.map((account) => (
                                <option key={account.id} value={account.id}>
                                    {account.code} — {account.name}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <div className="grid grid-cols-2 gap-4">
                        <Field label="Date" htmlFor="other-receipt-date" error={errors.receipt_date}>
                            <input
                                id="other-receipt-date"
                                type="date"
                                value={form.data.receipt_date}
                                onChange={(event) =>
                                    form.setData('receipt_date', event.target.value)
                                }
                                className={INPUT_CLASSES}
                            />
                        </Field>

                        <Field label="Amount" htmlFor="other-receipt-amount" error={errors.amount}>
                            <input
                                id="other-receipt-amount"
                                type="text"
                                inputMode="decimal"
                                placeholder="0.00"
                                value={form.data.amount}
                                onChange={(event) => form.setData('amount', event.target.value)}
                                className={`${INPUT_CLASSES} text-right font-mono tabular-nums`}
                            />
                        </Field>
                    </div>

                    <Field label="Payer" htmlFor="other-receipt-payer" error={errors.payer}>
                        <input
                            id="other-receipt-payer"
                            type="text"
                            value={form.data.payer}
                            onChange={(event) => form.setData('payer', event.target.value)}
                            placeholder="Who paid this? (optional)"
                            className={INPUT_CLASSES}
                        />
                    </Field>

                    <Field label="Memo" htmlFor="other-receipt-memo" error={errors.memo}>
                        <input
                            id="other-receipt-memo"
                            type="text"
                            value={form.data.memo}
                            onChange={(event) => form.setData('memo', event.target.value)}
                            placeholder="What is this receipt for?"
                            className={INPUT_CLASSES}
                        />
                    </Field>

                    <div className="mt-2 flex justify-end">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Recording…' : 'Record receipt'}
                        </Button>
                    </div>
                </form>
            </Sheet>
        </AppShell>
    );
}
