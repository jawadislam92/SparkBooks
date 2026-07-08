import Button from '@/Components/ui/Button';
import { parseMoneyInput } from '@/lib/money';
import {
    Combobox,
    ComboboxInput,
    ComboboxOption,
    ComboboxOptions,
} from '@headlessui/react';
import { useState } from 'react';

export interface AccountOption {
    id: number;
    code: string;
    name: string;
    type: string;
}

export interface LineRow {
    /** Client-only identity for React reconciliation — never sent to the server. */
    key: string;
    accountId: number | null;
    memo: string;
    /** Raw user-typed decimal text, e.g. "1500.50" — converted at submit time. */
    debitText: string;
    creditText: string;
}

export function emptyLineRow(): LineRow {
    return {
        key: crypto.randomUUID(),
        accountId: null,
        memo: '',
        debitText: '',
        creditText: '',
    };
}

export interface LineTotals {
    debitTotal: number;
    creditTotal: number;
    /** Lines with an account and exactly one valid, positive side. */
    completeCount: number;
    /** True if any row is half-filled, has both sides, or fails to parse. */
    hasBlockingError: boolean;
}

/** Empty text parses as 0 here (an untouched side), not as an error. */
function parseSideAmount(text: string): number | null {
    return text.trim() === '' ? 0 : parseMoneyInput(text);
}

function isBlankRow(row: LineRow): boolean {
    return (
        row.accountId === null &&
        row.memo.trim() === '' &&
        row.debitText.trim() === '' &&
        row.creditText.trim() === ''
    );
}

/** Sums valid lines and flags anything that would fail server validation. */
export function summarizeLines(rows: LineRow[]): LineTotals {
    let debitTotal = 0;
    let creditTotal = 0;
    let completeCount = 0;
    let hasBlockingError = false;

    for (const row of rows) {
        if (isBlankRow(row)) {
            continue;
        }

        const debit = parseSideAmount(row.debitText);
        const credit = parseSideAmount(row.creditText);

        if (debit === null || credit === null) {
            hasBlockingError = true;
            continue;
        }

        const debitPositive = debit > 0;
        const creditPositive = credit > 0;

        // Exactly one side must be positive, and an account must be chosen —
        // mirrors StoreManualJournalRequest's own per-line rule.
        if (row.accountId === null || debitPositive === creditPositive) {
            hasBlockingError = true;
            continue;
        }

        debitTotal += debit;
        creditTotal += credit;
        completeCount += 1;
    }

    return { debitTotal, creditTotal, completeCount, hasBlockingError };
}

export function canSubmitLines(rows: LineRow[]): boolean {
    const { debitTotal, creditTotal, completeCount, hasBlockingError } =
        summarizeLines(rows);

    return (
        !hasBlockingError &&
        completeCount >= 2 &&
        debitTotal === creditTotal &&
        debitTotal > 0
    );
}

export interface LinePayload {
    account_id: number;
    debit: number;
    credit: number;
    memo: string | null;
}

/** Only complete lines are sent — a half-filled spare row is dropped, not posted as zero. */
export function buildLinePayload(rows: LineRow[]): LinePayload[] {
    return rows
        .filter((row) => {
            if (row.accountId === null) {
                return false;
            }
            const debit = parseSideAmount(row.debitText) ?? 0;
            const credit = parseSideAmount(row.creditText) ?? 0;
            return (debit > 0) !== (credit > 0);
        })
        .map((row) => ({
            account_id: row.accountId as number,
            debit: parseSideAmount(row.debitText) ?? 0,
            credit: parseSideAmount(row.creditText) ?? 0,
            memo: row.memo.trim() === '' ? null : row.memo.trim(),
        }));
}

const CONTROL_CLASSES =
    'h-9 w-full rounded-md border bg-paper-raised px-3 text-sm text-ink placeholder:text-ink-700/50 transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

function controlBorder(hasError: boolean): string {
    return hasError ? 'border-clay' : 'border-rule';
}

function accountLabel(account: AccountOption): string {
    return `${account.code} — ${account.name}`;
}

function AccountCombobox({
    accounts,
    value,
    onChange,
    label,
    error,
}: {
    accounts: AccountOption[];
    value: number | null;
    onChange: (accountId: number | null) => void;
    label: string;
    error?: string;
}) {
    const [query, setQuery] = useState('');
    const selected = accounts.find((account) => account.id === value) ?? null;

    const filtered =
        query === ''
            ? accounts
            : accounts.filter((account) => {
                  const needle = query.toLowerCase();
                  return (
                      account.code.toLowerCase().includes(needle) ||
                      account.name.toLowerCase().includes(needle)
                  );
              });

    return (
        <div>
            <Combobox
                value={selected}
                onChange={(account: AccountOption | null) =>
                    onChange(account ? account.id : null)
                }
                onClose={() => setQuery('')}
            >
                <div className="relative">
                    <ComboboxInput
                        aria-label={label}
                        aria-invalid={Boolean(error)}
                        displayValue={(account: AccountOption | null) =>
                            account ? accountLabel(account) : ''
                        }
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Search account…"
                        className={`${CONTROL_CLASSES} ${controlBorder(Boolean(error))}`}
                    />
                    <ComboboxOptions
                        className="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-md border border-rule bg-paper-raised py-1 shadow-sheet empty:hidden"
                    >
                        {filtered.length === 0 ? (
                            <div className="px-3 py-2 text-sm text-ink-700">
                                No matching accounts
                            </div>
                        ) : (
                            filtered.map((account) => (
                                <ComboboxOption
                                    key={account.id}
                                    value={account}
                                    className="cursor-pointer select-none px-3 py-2 text-sm text-ink data-[focus]:bg-spark-50"
                                >
                                    {accountLabel(account)}
                                </ComboboxOption>
                            ))
                        )}
                    </ComboboxOptions>
                </div>
            </Combobox>
            {error && (
                <p role="alert" className="mt-1 text-xs text-clay">
                    {error}
                </p>
            )}
        </div>
    );
}

function MoneyFieldInput({
    label,
    value,
    onChange,
    error,
}: {
    label: string;
    value: string;
    onChange: (text: string) => void;
    error?: string;
}) {
    const invalid = value.trim() !== '' && parseMoneyInput(value) === null;
    const message = error ?? (invalid ? 'Enter a valid amount' : undefined);

    return (
        <div>
            <input
                type="text"
                inputMode="decimal"
                aria-label={label}
                aria-invalid={Boolean(message)}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                placeholder="0.00"
                className={`${CONTROL_CLASSES} text-right font-mono tabular-nums ${controlBorder(Boolean(message))}`}
            />
            {message && (
                <p role="alert" className="mt-1 text-right text-xs text-clay">
                    {message}
                </p>
            )}
        </div>
    );
}

const GRID_COLUMNS =
    'grid grid-cols-[minmax(0,3fr)_minmax(0,2fr)_128px_128px_36px] items-start gap-3';

export interface LineEditorProps {
    rows: LineRow[];
    accounts: AccountOption[];
    onChange: (rows: LineRow[]) => void;
    /** Flat error map keyed like the server's session errors, e.g. "lines.0.debit". */
    errors?: Record<string, string | undefined>;
}

/**
 * The manual-journal line grid: account (searchable), optional line memo, and
 * one-sided debit/credit amounts. Entering a value on one side of a row
 * clears the other — a line can never be both a debit and a credit.
 */
export default function LineEditor({
    rows,
    accounts,
    onChange,
    errors = {},
}: LineEditorProps) {
    function updateRow(index: number, patch: Partial<LineRow>) {
        const next = rows.slice();
        next[index] = { ...next[index], ...patch };
        onChange(next);
    }

    function handleDebitChange(index: number, text: string) {
        updateRow(index, {
            debitText: text,
            creditText: text.trim() === '' ? rows[index].creditText : '',
        });
    }

    function handleCreditChange(index: number, text: string) {
        updateRow(index, {
            creditText: text,
            debitText: text.trim() === '' ? rows[index].debitText : '',
        });
    }

    function addRow() {
        onChange([...rows, emptyLineRow()]);
    }

    function removeRow(index: number) {
        if (rows.length <= 2) {
            return;
        }
        onChange(rows.filter((_, rowIndex) => rowIndex !== index));
    }

    return (
        <div>
            <div
                className={`${GRID_COLUMNS} border-b border-rule px-1 pb-2 text-xs font-medium uppercase tracking-wide text-ink-700`}
            >
                <span>Account</span>
                <span>Line memo</span>
                <span className="text-right">Debit</span>
                <span className="text-right">Credit</span>
                <span className="sr-only">Remove</span>
            </div>

            {rows.map((row, index) => (
                <div
                    key={row.key}
                    className={`${GRID_COLUMNS} border-b border-rule py-2 last:border-b-0`}
                >
                    <AccountCombobox
                        accounts={accounts}
                        value={row.accountId}
                        onChange={(accountId) => updateRow(index, { accountId })}
                        label={`Account, line ${index + 1}`}
                        error={errors[`lines.${index}.account_id`]}
                    />
                    <input
                        type="text"
                        aria-label={`Line memo, line ${index + 1}`}
                        value={row.memo}
                        onChange={(event) =>
                            updateRow(index, { memo: event.target.value })
                        }
                        placeholder="Optional memo"
                        className={`${CONTROL_CLASSES} border-rule`}
                    />
                    <MoneyFieldInput
                        label={`Debit amount, line ${index + 1}`}
                        value={row.debitText}
                        onChange={(text) => handleDebitChange(index, text)}
                        error={errors[`lines.${index}.debit`]}
                    />
                    <MoneyFieldInput
                        label={`Credit amount, line ${index + 1}`}
                        value={row.creditText}
                        onChange={(text) => handleCreditChange(index, text)}
                        error={errors[`lines.${index}.credit`]}
                    />
                    <button
                        type="button"
                        onClick={() => removeRow(index)}
                        disabled={rows.length <= 2}
                        aria-label={`Remove line ${index + 1}`}
                        className="mt-1 flex h-8 w-8 items-center justify-center rounded-md text-ink-700 transition duration-[120ms] ease-out motion-reduce:transition-none hover:bg-paper hover:text-clay focus:outline-none focus-visible:ring-2 focus-visible:ring-spark disabled:cursor-not-allowed disabled:opacity-30"
                    >
                        <svg
                            aria-hidden="true"
                            viewBox="0 0 20 20"
                            fill="currentColor"
                            className="h-4 w-4"
                        >
                            <path
                                fillRule="evenodd"
                                d="M8.75 1a.75.75 0 00-.75.75V3H4a.75.75 0 000 1.5h.278l.7 11.19A2 2 0 006.976 17.5h6.048a2 2 0 001.997-1.81l.7-11.19H16A.75.75 0 0016 3h-4v-1.25a.75.75 0 00-.75-.75h-2.5zM8 6.75a.75.75 0 011.5 0v6.5a.75.75 0 01-1.5 0v-6.5zm4.5 0a.75.75 0 00-1.5 0v6.5a.75.75 0 001.5 0v-6.5z"
                                clipRule="evenodd"
                            />
                        </svg>
                    </button>
                </div>
            ))}

            <Button
                type="button"
                variant="quiet"
                size="sm"
                onClick={addRow}
                className="mt-3"
            >
                + Add line
            </Button>
        </div>
    );
}
