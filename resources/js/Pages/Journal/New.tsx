import BalanceBeam from '@/Components/ledger/BalanceBeam';
import LineEditor, {
    AccountOption,
    LineRow,
    buildLinePayload,
    canSubmitLines,
    emptyLineRow,
    summarizeLines,
} from '@/Components/ledger/LineEditor';
import Button from '@/Components/ui/Button';
import Field from '@/Components/ui/Field';
import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useMemo, useState } from 'react';

interface NewJournalPageProps extends PageProps {
    accounts: AccountOption[];
}

interface JournalFormData {
    entry_date: string;
    memo: string;
    lines: LineRow[];
}

function todayIsoDate(): string {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

const INPUT_CLASSES =
    'h-10 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

export default function New({ accounts }: NewJournalPageProps) {
    const { currentCompany } = usePage<PageProps>().props;
    const currency = currentCompany?.base_currency ?? 'USD';

    // One idempotency key per form-open — stable across retries after a
    // validation error, so a resubmit of the same intent never double-posts.
    const [idempotencyKey] = useState(() => crypto.randomUUID());

    const form = useForm<JournalFormData>({
        entry_date: todayIsoDate(),
        memo: '',
        lines: [emptyLineRow(), emptyLineRow()],
    });

    // The server returns flat, dot-pathed keys (e.g. "lines.0.debit") that
    // don't line up with useForm's per-field generic typing — treat errors
    // as a loose lookup table instead of fighting that mismatch.
    const errors = form.errors as Record<string, string | undefined>;

    const totals = useMemo(() => summarizeLines(form.data.lines), [form.data.lines]);
    const canSubmit = useMemo(
        () => canSubmitLines(form.data.lines),
        [form.data.lines],
    );

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        if (!canSubmit || form.processing) {
            return;
        }

        form.transform((data) => ({
            entry_date: data.entry_date,
            memo: data.memo,
            lines: buildLinePayload(data.lines),
        }));

        form.post(route('journal-entries.store'), {
            headers: { 'X-Idempotency-Key': idempotencyKey },
            preserveScroll: true,
        });
    };

    return (
        <AppShell title="New journal entry">
            <Head title="New journal entry" />

            <Sheet title="New journal entry">
                <form onSubmit={submit} className="flex flex-col gap-6">
                    {errors.lines && (
                        <p
                            role="alert"
                            className="rounded-md bg-clay-50 px-3 py-2 text-sm text-clay"
                        >
                            {errors.lines}
                        </p>
                    )}
                    {errors.idempotency_key && (
                        <p
                            role="alert"
                            className="rounded-md bg-clay-50 px-3 py-2 text-sm text-clay"
                        >
                            {errors.idempotency_key}
                        </p>
                    )}

                    <div className="grid grid-cols-2 gap-4">
                        <Field label="Date" htmlFor="entry_date" error={errors.entry_date}>
                            <input
                                id="entry_date"
                                type="date"
                                value={form.data.entry_date}
                                onChange={(event) =>
                                    form.setData('entry_date', event.target.value)
                                }
                                className={INPUT_CLASSES}
                            />
                        </Field>
                        <Field label="Memo" htmlFor="memo" error={errors.memo}>
                            <input
                                id="memo"
                                type="text"
                                value={form.data.memo}
                                onChange={(event) =>
                                    form.setData('memo', event.target.value)
                                }
                                placeholder="What is this entry for?"
                                className={INPUT_CLASSES}
                            />
                        </Field>
                    </div>

                    <LineEditor
                        rows={form.data.lines}
                        accounts={accounts}
                        onChange={(lines) => form.setData('lines', lines)}
                        errors={errors}
                    />

                    <div className="border-t border-rule pt-6">
                        <div className="flex items-start justify-center gap-16">
                            <div className="text-center">
                                <p className="text-xs font-medium uppercase tracking-wide text-ink-700">
                                    Debit
                                </p>
                                <MoneyText
                                    minor={totals.debitTotal}
                                    currency={currency}
                                    size="lg"
                                />
                            </div>
                            <div className="text-center">
                                <p className="text-xs font-medium uppercase tracking-wide text-ink-700">
                                    Credit
                                </p>
                                <MoneyText
                                    minor={totals.creditTotal}
                                    currency={currency}
                                    size="lg"
                                />
                            </div>
                        </div>
                        <BalanceBeam
                            debitTotal={totals.debitTotal}
                            creditTotal={totals.creditTotal}
                            currency={currency}
                            className="mx-auto mt-4 max-w-sm"
                        />
                    </div>

                    <div className="flex justify-end">
                        <Button type="submit" disabled={!canSubmit || form.processing}>
                            {form.processing ? 'Posting…' : 'Post entry'}
                        </Button>
                    </div>
                </form>
            </Sheet>
        </AppShell>
    );
}
