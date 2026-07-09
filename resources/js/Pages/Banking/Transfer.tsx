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

interface TransferPageProps extends PageProps {
    bankAccounts: BankAccountOption[];
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

interface TransferFormData {
    from_bank_account_id: string;
    to_bank_account_id: string;
    transfer_date: string;
    amount: string;
    memo: string;
}

export default function Transfer({ bankAccounts }: TransferPageProps) {
    // One idempotency key per page-open — stable across retries after a
    // validation error, so a resubmit of the same intent never double-posts.
    const [idempotencyKey] = useState(() => crypto.randomUUID());

    const form = useForm<TransferFormData>({
        from_bank_account_id: '',
        to_bank_account_id: '',
        transfer_date: todayIsoDate(),
        amount: '',
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
            from_bank_account_id:
                data.from_bank_account_id === '' ? null : Number(data.from_bank_account_id),
            to_bank_account_id:
                data.to_bank_account_id === '' ? null : Number(data.to_bank_account_id),
            transfer_date: data.transfer_date,
            amount: minorAmount,
            memo: data.memo === '' ? null : data.memo,
        }));

        form.post(route('transfers.store'), {
            headers: { 'X-Idempotency-Key': idempotencyKey },
            preserveScroll: true,
        });
    };

    return (
        <AppShell title="Transfer money">
            <Head title="Transfer money" />

            <Sheet title="Transfer between accounts">
                <form onSubmit={submit} className="flex max-w-lg flex-col gap-4">
                    {errors.idempotency_key && (
                        <p
                            role="alert"
                            className="rounded-md bg-clay-50 px-3 py-2 text-sm text-clay"
                        >
                            {errors.idempotency_key}
                        </p>
                    )}

                    <Field label="From" htmlFor="transfer-from" error={errors.from_bank_account_id}>
                        <select
                            id="transfer-from"
                            value={form.data.from_bank_account_id}
                            onChange={(event) =>
                                form.setData('from_bank_account_id', event.target.value)
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

                    <Field label="To" htmlFor="transfer-to" error={errors.to_bank_account_id}>
                        <select
                            id="transfer-to"
                            value={form.data.to_bank_account_id}
                            onChange={(event) =>
                                form.setData('to_bank_account_id', event.target.value)
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

                    <div className="grid grid-cols-2 gap-4">
                        <Field
                            label="Date"
                            htmlFor="transfer-date"
                            error={errors.transfer_date}
                        >
                            <input
                                id="transfer-date"
                                type="date"
                                value={form.data.transfer_date}
                                onChange={(event) =>
                                    form.setData('transfer_date', event.target.value)
                                }
                                className={INPUT_CLASSES}
                            />
                        </Field>

                        <Field label="Amount" htmlFor="transfer-amount" error={errors.amount}>
                            <input
                                id="transfer-amount"
                                type="text"
                                inputMode="decimal"
                                placeholder="0.00"
                                value={form.data.amount}
                                onChange={(event) =>
                                    form.setData('amount', event.target.value)
                                }
                                className={`${INPUT_CLASSES} text-right font-mono tabular-nums`}
                            />
                        </Field>
                    </div>

                    <Field label="Memo" htmlFor="transfer-memo" error={errors.memo}>
                        <input
                            id="transfer-memo"
                            type="text"
                            value={form.data.memo}
                            onChange={(event) => form.setData('memo', event.target.value)}
                            placeholder="What is this transfer for?"
                            className={INPUT_CLASSES}
                        />
                    </Field>

                    <div className="mt-2 flex justify-end">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Transferring…' : 'Transfer'}
                        </Button>
                    </div>
                </form>
            </Sheet>
        </AppShell>
    );
}
