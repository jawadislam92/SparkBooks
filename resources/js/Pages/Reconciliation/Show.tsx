import Button from '@/Components/ui/Button';
import Field from '@/Components/ui/Field';
import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { minorToDecimalString, parseMoneyInput } from '@/lib/money';
import { PageProps } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import {
    Dialog,
    DialogBackdrop,
    DialogPanel,
    DialogTitle,
} from '@headlessui/react';
import { FormEventHandler, useEffect, useMemo, useState } from 'react';

interface PreviewLine {
    id: number;
    entry_date: string;
    memo: string | null;
    /** Signed minor units — the line's own effect (debit − credit) on this bank account. */
    amount: number;
}

interface Preview {
    lines: PreviewLine[];
    opening_reconciled_balance: number;
    statement_ending_balance: number;
}

interface HistoryRow {
    id: number;
    statement_date: string;
    statement_ending_balance: number;
    opening_reconciled_balance: number;
    status: 'completed' | 'reopened';
    completed_at: string;
    can_reopen: boolean;
}

interface ShowPageProps extends PageProps {
    bankAccount: { id: number; name: string; currency_code: string };
    history: HistoryRow[];
    preview: Preview | null;
    statementDate: string | null;
}

interface CompleteFormData {
    statement_date: string;
    statement_ending_balance: number;
    ticked_journal_line_ids: number[];
}

const INPUT_CLASSES =
    'h-10 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

const STATUS_LABELS: Record<HistoryRow['status'], string> = {
    completed: 'Completed',
    reopened: 'Reopened',
};

// completed = spark tint, reopened = amber tint — never gray/indigo/hex.
const STATUS_CHIP_CLASSES: Record<HistoryRow['status'], string> = {
    completed: 'bg-spark-50 text-ink',
    reopened: 'bg-amber-50 text-amber',
};

function todayIsoDate(): string {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

/**
 * One bank account's reconciliation workspace (spec §7.9): a history of
 * past reconciliations (Reopen only ever offered on the newest completed
 * row — mirrors ReconciliationService's own newest-first guard), and a
 * statement-entry flow that loads the account's currently-unreconciled
 * lines, lets the user tick which ones appear on the statement, and shows
 * the LIVE difference — statement balance − (opening reconciled + ticked) —
 * recomputed client-side as ticks change. Finish is disabled until that
 * difference is exactly 0.
 */
export default function Show({ bankAccount, history, preview, statementDate }: ShowPageProps) {
    const [statementDateInput, setStatementDateInput] = useState(statementDate ?? todayIsoDate());
    const [statementBalanceInput, setStatementBalanceInput] = useState(
        preview ? minorToDecimalString(preview.statement_ending_balance) : '',
    );
    const [balanceError, setBalanceError] = useState<string | null>(null);
    const [ticked, setTicked] = useState<Record<number, boolean>>({});
    const [idempotencyKey, setIdempotencyKey] = useState(() => crypto.randomUUID());
    const [reopenTarget, setReopenTarget] = useState<HistoryRow | null>(null);
    const [reopening, setReopening] = useState(false);

    // A freshly loaded (or reloaded) preview always starts with nothing
    // ticked — ticking is a per-session action, never carried over.
    useEffect(() => {
        setTicked({});
    }, [preview]);

    const completeForm = useForm<CompleteFormData>({
        statement_date: statementDateInput,
        statement_ending_balance: preview?.statement_ending_balance ?? 0,
        ticked_journal_line_ids: [],
    });
    const errors = completeForm.errors as Record<string, string | undefined>;

    const tickedIds = useMemo(
        () => Object.keys(ticked).map(Number).filter((id) => ticked[id]),
        [ticked],
    );

    const tickedTotal = useMemo(() => {
        if (!preview) {
            return 0;
        }

        return preview.lines
            .filter((line) => ticked[line.id])
            .reduce((sum, line) => sum + line.amount, 0);
    }, [preview, ticked]);

    // THE live difference (spec §7.9): statement balance − (opening
    // reconciled + ticked). Finish is enabled ONLY when this is exactly 0.
    const difference = preview
        ? preview.statement_ending_balance - (preview.opening_reconciled_balance + tickedTotal)
        : null;
    const canFinish = preview !== null && difference === 0;

    function toggleLine(id: number) {
        setTicked((current) => ({ ...current, [id]: !current[id] }));
    }

    const loadStatement: FormEventHandler = (event) => {
        event.preventDefault();

        const minorBalance = parseMoneyInput(statementBalanceInput);
        if (minorBalance === null) {
            setBalanceError('Enter a valid ending balance.');
            return;
        }
        setBalanceError(null);

        router.get(
            route('reconciliations.show', bankAccount.id),
            {
                statement_date: statementDateInput,
                statement_ending_balance: minorBalance,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    function finish() {
        if (!canFinish || completeForm.processing || !preview) {
            return;
        }

        completeForm.transform(() => ({
            statement_date: statementDateInput,
            statement_ending_balance: preview.statement_ending_balance,
            ticked_journal_line_ids: tickedIds,
        }));

        completeForm.post(route('reconciliations.complete', bankAccount.id), {
            headers: { 'X-Idempotency-Key': idempotencyKey },
            preserveScroll: true,
            onSuccess: () => setIdempotencyKey(crypto.randomUUID()),
        });
    }

    function confirmReopen() {
        if (!reopenTarget || reopening) {
            return;
        }

        setReopening(true);
        router.post(
            route('reconciliations.reopen', reopenTarget.id),
            {},
            {
                headers: { 'X-Idempotency-Key': crypto.randomUUID() },
                preserveScroll: true,
                onFinish: () => {
                    setReopening(false);
                    setReopenTarget(null);
                },
            },
        );
    }

    return (
        <AppShell title={`Reconcile ${bankAccount.name}`}>
            <Head title={`Reconcile ${bankAccount.name}`} />

            <div className="flex flex-col gap-6">
                <Sheet title="New statement">
                    <form onSubmit={loadStatement} className="flex flex-wrap items-end gap-4">
                        <Field label="Statement end date" htmlFor="statement-date" className="w-48">
                            <input
                                id="statement-date"
                                type="date"
                                value={statementDateInput}
                                onChange={(event) => setStatementDateInput(event.target.value)}
                                className={INPUT_CLASSES}
                            />
                        </Field>
                        <Field
                            label="Statement ending balance"
                            htmlFor="statement-balance"
                            error={balanceError ?? undefined}
                            className="w-48"
                        >
                            <input
                                id="statement-balance"
                                type="text"
                                inputMode="decimal"
                                placeholder="0.00"
                                value={statementBalanceInput}
                                onChange={(event) => setStatementBalanceInput(event.target.value)}
                                className={`${INPUT_CLASSES} text-right font-mono tabular-nums`}
                            />
                        </Field>
                        <Button type="submit">Load statement</Button>
                    </form>
                </Sheet>

                {preview && (
                    <Sheet title="Tick the lines on your statement">
                        {errors.status && (
                            <p
                                role="alert"
                                className="mb-4 rounded-md bg-clay-50 px-3 py-2 text-sm text-clay"
                            >
                                {errors.status}
                            </p>
                        )}

                        {preview.lines.length === 0 ? (
                            <p className="py-6 text-center text-sm text-ink-700">
                                No unreconciled activity through this date.
                            </p>
                        ) : (
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-rule text-left text-xs uppercase tracking-wide text-ink-700">
                                        <th className="w-10 py-2"></th>
                                        <th className="py-2">Date</th>
                                        <th className="py-2">Memo</th>
                                        <th className="py-2 text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {preview.lines.map((line) => (
                                        <tr key={line.id} className="border-b border-rule last:border-0">
                                            <td className="py-2">
                                                <input
                                                    type="checkbox"
                                                    checked={!!ticked[line.id]}
                                                    onChange={() => toggleLine(line.id)}
                                                    className="h-4 w-4 rounded border-rule text-spark focus:ring-spark"
                                                    aria-label={`Tick line dated ${line.entry_date}`}
                                                />
                                            </td>
                                            <td className="py-2 text-ink-700">{line.entry_date}</td>
                                            <td className="py-2 text-ink-700">{line.memo ?? '—'}</td>
                                            <td className="py-2">
                                                <MoneyText
                                                    minor={line.amount}
                                                    currency={bankAccount.currency_code}
                                                    size="sm"
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}

                        <div className="mt-6 flex flex-wrap items-end justify-between gap-6 border-t border-rule pt-6">
                            <div className="flex gap-8">
                                <div>
                                    <p className="text-xs font-medium uppercase tracking-wide text-ink-700">
                                        Opening reconciled
                                    </p>
                                    <MoneyText
                                        minor={preview.opening_reconciled_balance}
                                        currency={bankAccount.currency_code}
                                    />
                                </div>
                                <div>
                                    <p className="text-xs font-medium uppercase tracking-wide text-ink-700">
                                        Ticked total
                                    </p>
                                    <MoneyText minor={tickedTotal} currency={bankAccount.currency_code} />
                                </div>
                                <div>
                                    <p className="text-xs font-medium uppercase tracking-wide text-ink-700">
                                        Statement balance
                                    </p>
                                    <MoneyText
                                        minor={preview.statement_ending_balance}
                                        currency={bankAccount.currency_code}
                                    />
                                </div>
                                <div>
                                    <p className="text-xs font-medium uppercase tracking-wide text-ink-700">
                                        Difference
                                    </p>
                                    <MoneyText
                                        minor={difference ?? 0}
                                        currency={bankAccount.currency_code}
                                        size="lg"
                                        className={difference === 0 ? 'text-spark' : 'text-clay'}
                                    />
                                </div>
                            </div>

                            <Button
                                type="button"
                                onClick={finish}
                                disabled={!canFinish || completeForm.processing}
                            >
                                {completeForm.processing ? 'Finishing…' : 'Finish reconciliation'}
                            </Button>
                        </div>
                    </Sheet>
                )}

                <Sheet title="History">
                    {history.length === 0 ? (
                        <p className="py-6 text-center text-sm text-ink-700">
                            No reconciliations yet for this account.
                        </p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-rule text-left text-xs uppercase tracking-wide text-ink-700">
                                    <th className="py-2">Statement date</th>
                                    <th className="py-2 text-right">Opening</th>
                                    <th className="py-2 text-right">Ending</th>
                                    <th className="py-2">Status</th>
                                    <th className="py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {history.map((row) => (
                                    <tr key={row.id} className="border-b border-rule last:border-0">
                                        <td className="py-2 text-ink-700">{row.statement_date}</td>
                                        <td className="py-2">
                                            <MoneyText
                                                minor={row.opening_reconciled_balance}
                                                currency={bankAccount.currency_code}
                                                size="sm"
                                            />
                                        </td>
                                        <td className="py-2">
                                            <MoneyText
                                                minor={row.statement_ending_balance}
                                                currency={bankAccount.currency_code}
                                                size="sm"
                                            />
                                        </td>
                                        <td className="py-2">
                                            <span
                                                className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_CHIP_CLASSES[row.status]}`}
                                            >
                                                {STATUS_LABELS[row.status]}
                                            </span>
                                        </td>
                                        <td className="py-2 text-right">
                                            {row.can_reopen && (
                                                <Button
                                                    type="button"
                                                    variant="quiet"
                                                    size="sm"
                                                    onClick={() => setReopenTarget(row)}
                                                >
                                                    Reopen
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </Sheet>
            </div>

            {reopenTarget && (
                <Dialog open onClose={() => setReopenTarget(null)} className="relative z-50">
                    <DialogBackdrop className="fixed inset-0 bg-ink/30" />
                    <div className="fixed inset-0 flex items-center justify-center p-4">
                        <DialogPanel className="w-full max-w-md rounded-sheet bg-paper-raised p-6 shadow-sheet">
                            <DialogTitle className="font-display text-lg font-semibold text-ink">
                                Reopen this reconciliation?
                            </DialogTitle>
                            <p className="mt-2 text-sm text-ink-700">
                                The statement dated {reopenTarget.statement_date} will unlock — its
                                lines become unreconciled again. This must be the most recent
                                reconciliation for this account.
                            </p>
                            <div className="mt-6 flex justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="quiet"
                                    onClick={() => setReopenTarget(null)}
                                >
                                    Cancel
                                </Button>
                                <Button type="button" onClick={confirmReopen} disabled={reopening}>
                                    {reopening ? 'Reopening…' : 'Reopen'}
                                </Button>
                            </div>
                        </DialogPanel>
                    </div>
                </Dialog>
            )}
        </AppShell>
    );
}
