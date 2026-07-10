import Button from '@/Components/ui/Button';
import MoneyText from '@/Components/ui/MoneyText';
import { computeLineTax, parseMoneyInput } from '@/lib/money';

export interface CategoryAccountOption {
    id: number;
    code: string;
    name: string;
}

export interface TaxRateOption {
    id: number;
    name: string;
    /** Decimal string straight from the DECIMAL(7,4) column, e.g. "18.0000". */
    rate: string;
}

export interface DocumentLineRow {
    /** Client-only identity for React reconciliation — never sent to the server. */
    key: string;
    description: string;
    categoryAccountId: number | null;
    taxRateId: number | null;
    /** Raw user-typed decimal text, e.g. "1500.50" — converted via parseMoneyInput at submit time. */
    amountText: string;
}

export function emptyDocumentLineRow(): DocumentLineRow {
    return {
        key: crypto.randomUUID(),
        description: '',
        categoryAccountId: null,
        taxRateId: null,
        amountText: '',
    };
}

export interface DocumentLinePayload {
    description: string;
    category_account_id: number | null;
    tax_rate_id: number | null;
    amount: number;
}

/** Shapes every row for submission — the amount-based mirror of buildInvoiceLinePayload. */
export function buildDocumentLinePayload(rows: DocumentLineRow[]): DocumentLinePayload[] {
    return rows.map((row) => ({
        description: row.description.trim(),
        category_account_id: row.categoryAccountId,
        tax_rate_id: row.taxRateId,
        amount: parseMoneyInput(row.amountText) ?? 0,
    }));
}

/** A single row's live tax, mirroring DocumentLineCalculator's per-line computation. */
export function computeDocumentLineTax(row: DocumentLineRow, taxRates: TaxRateOption[]): number {
    const amount = parseMoneyInput(row.amountText) ?? 0;
    const taxRate =
        row.taxRateId === null ? null : (taxRates.find((t) => t.id === row.taxRateId) ?? null);

    return taxRate === null ? 0 : computeLineTax(amount, taxRate.rate);
}

export interface DocumentTotals {
    subtotal: number;
    taxTotal: number;
    total: number;
}

/** The document-level live preview, mirroring DocumentLineCalculator::compute's document totals. */
export function computeDocumentTotals(rows: DocumentLineRow[], taxRates: TaxRateOption[]): DocumentTotals {
    let subtotal = 0;
    let taxTotal = 0;

    for (const row of rows) {
        const amount = parseMoneyInput(row.amountText) ?? 0;
        subtotal += amount;
        taxTotal += computeDocumentLineTax(row, taxRates);
    }

    return { subtotal, taxTotal, total: subtotal + taxTotal };
}

const CONTROL_CLASSES =
    'h-9 w-full rounded-md border bg-paper-raised px-3 text-sm text-ink placeholder:text-ink-700/50 transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

function controlBorder(hasError: boolean): string {
    return hasError ? 'border-clay' : 'border-rule';
}

const GRID_COLUMNS =
    'grid grid-cols-[minmax(0,2.2fr)_minmax(0,1.6fr)_130px_minmax(0,1.1fr)_112px_32px] items-start gap-2';

export interface DocumentLineEditorProps {
    rows: DocumentLineRow[];
    categoryAccounts: CategoryAccountOption[];
    taxRates: TaxRateOption[];
    currency: string;
    onChange: (rows: DocumentLineRow[]) => void;
    /** Flat error map keyed like the server's session errors, e.g. "lines.0.category_account_id". */
    errors?: Record<string, string | undefined>;
}

/**
 * The shared expense/bill line grid (spec §7.8: AMOUNT-based lines — a
 * category account + net amount + optional tax, no qty x unit price). Mirrors
 * InvoiceLineEditor's shape/interaction pattern (add/remove min 1, live
 * per-line total) but with the category-account picker in place of an income
 * account and a single amount field in place of qty x unit price. The
 * category options passed in are already filtered to eligible accounts only
 * (App\Support\CategoryAccountGuard::eligibleQuery, via ExpenseController/
 * BillController's own formOptions()) — this component never re-derives
 * eligibility client-side. Amount/Tax/Total labels only — never debit/credit
 * (PROJECT_MAP §9 rule 8).
 */
export default function DocumentLineEditor({
    rows,
    categoryAccounts,
    taxRates,
    currency,
    onChange,
    errors = {},
}: DocumentLineEditorProps) {
    function updateRow(index: number, patch: Partial<DocumentLineRow>) {
        const next = rows.slice();
        next[index] = { ...next[index], ...patch };
        onChange(next);
    }

    function addRow() {
        onChange([...rows, emptyDocumentLineRow()]);
    }

    function removeRow(index: number) {
        if (rows.length <= 1) {
            return;
        }
        onChange(rows.filter((_, rowIndex) => rowIndex !== index));
    }

    return (
        <div>
            <div
                className={`${GRID_COLUMNS} border-b border-rule px-1 pb-2 text-xs font-medium uppercase tracking-wide text-ink-700`}
            >
                <span>Description</span>
                <span>Category account</span>
                <span className="text-right">Amount</span>
                <span>Tax rate</span>
                <span className="text-right">Line total</span>
                <span className="sr-only">Remove</span>
            </div>

            {rows.map((row, index) => {
                const amount = parseMoneyInput(row.amountText) ?? 0;
                const lineTax = computeDocumentLineTax(row, taxRates);
                const descriptionError = errors[`lines.${index}.description`];
                const categoryAccountError = errors[`lines.${index}.category_account_id`];
                const amountError = errors[`lines.${index}.amount`];
                const taxRateError = errors[`lines.${index}.tax_rate_id`];

                return (
                    <div
                        key={row.key}
                        className={`${GRID_COLUMNS} border-b border-rule py-2 last:border-b-0`}
                    >
                        <div className="flex flex-col gap-1">
                            <input
                                type="text"
                                aria-label={`Description, line ${index + 1}`}
                                aria-invalid={Boolean(descriptionError)}
                                value={row.description}
                                onChange={(event) => updateRow(index, { description: event.target.value })}
                                placeholder="Description"
                                className={`${CONTROL_CLASSES} ${controlBorder(Boolean(descriptionError))}`}
                            />
                            {descriptionError && (
                                <p role="alert" className="text-xs text-clay">
                                    {descriptionError}
                                </p>
                            )}
                        </div>

                        <div>
                            <select
                                aria-label={`Category account, line ${index + 1}`}
                                aria-invalid={Boolean(categoryAccountError)}
                                value={row.categoryAccountId ?? ''}
                                onChange={(event) =>
                                    updateRow(index, {
                                        categoryAccountId:
                                            event.target.value === '' ? null : Number(event.target.value),
                                    })
                                }
                                className={`${CONTROL_CLASSES} ${controlBorder(Boolean(categoryAccountError))}`}
                            >
                                <option value="">Select…</option>
                                {categoryAccounts.map((account) => (
                                    <option key={account.id} value={account.id}>
                                        {account.code} — {account.name}
                                    </option>
                                ))}
                            </select>
                            {categoryAccountError && (
                                <p role="alert" className="mt-1 text-xs text-clay">
                                    {categoryAccountError}
                                </p>
                            )}
                        </div>

                        <div>
                            <input
                                type="text"
                                inputMode="decimal"
                                aria-label={`Amount, line ${index + 1}`}
                                aria-invalid={Boolean(amountError)}
                                placeholder="0.00"
                                value={row.amountText}
                                onChange={(event) => updateRow(index, { amountText: event.target.value })}
                                className={`${CONTROL_CLASSES} text-right font-mono tabular-nums ${controlBorder(Boolean(amountError))}`}
                            />
                            {amountError && (
                                <p role="alert" className="mt-1 text-right text-xs text-clay">
                                    {amountError}
                                </p>
                            )}
                        </div>

                        <div>
                            <select
                                aria-label={`Tax rate, line ${index + 1}`}
                                aria-invalid={Boolean(taxRateError)}
                                value={row.taxRateId ?? ''}
                                onChange={(event) =>
                                    updateRow(index, {
                                        taxRateId: event.target.value === '' ? null : Number(event.target.value),
                                    })
                                }
                                className={`${CONTROL_CLASSES} ${controlBorder(Boolean(taxRateError))}`}
                            >
                                <option value="">No tax</option>
                                {taxRates.map((taxRate) => (
                                    <option key={taxRate.id} value={taxRate.id}>
                                        {taxRate.name}
                                    </option>
                                ))}
                            </select>
                            {taxRateError && (
                                <p role="alert" className="mt-1 text-xs text-clay">
                                    {taxRateError}
                                </p>
                            )}
                        </div>

                        <MoneyText minor={amount + lineTax} currency={currency} size="sm" className="mt-2" />

                        <button
                            type="button"
                            onClick={() => removeRow(index)}
                            disabled={rows.length <= 1}
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
                );
            })}

            <Button type="button" variant="quiet" size="sm" onClick={addRow} className="mt-3">
                + Add line
            </Button>
        </div>
    );
}
