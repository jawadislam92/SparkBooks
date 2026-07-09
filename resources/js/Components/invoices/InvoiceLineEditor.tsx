import Button from '@/Components/ui/Button';
import MoneyText from '@/Components/ui/MoneyText';
import {
    computeLineTax,
    minorToDecimalString,
    multiplyMinorByQuantity,
    parseMoneyInput,
} from '@/lib/money';

export interface ItemOption {
    id: number;
    name: string;
    description: string | null;
    /** Integer minor units. */
    unit_price: number;
    default_income_account_id: number | null;
    default_tax_rate_id: number | null;
}

export interface IncomeAccountOption {
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

export interface InvoiceLineRow {
    /** Client-only identity for React reconciliation — never sent to the server. */
    key: string;
    /**
     * Client-only prefill hint — invoice_lines has no item_id column (see
     * database/migrations/..._create_invoice_lines_table.php), so this is
     * never part of the submitted payload beyond the optional existence
     * check StoreInvoiceRequest runs. Selecting an item copies its
     * description/price/account/tax defaults into the row's own fields
     * below, which remain freely editable afterward and are what's
     * actually submitted.
     */
    itemId: number | null;
    description: string;
    /** Raw user-typed decimal text, up to 4dp, e.g. "2.5". */
    quantityText: string;
    /** Raw user-typed decimal text, e.g. "1500.50" — converted via parseMoneyInput at submit time. */
    unitPriceText: string;
    incomeAccountId: number | null;
    taxRateId: number | null;
}

export function emptyInvoiceLineRow(): InvoiceLineRow {
    return {
        key: crypto.randomUUID(),
        itemId: null,
        description: '',
        quantityText: '1',
        unitPriceText: '',
        incomeAccountId: null,
        taxRateId: null,
    };
}

export interface InvoiceLinePayload {
    description: string;
    quantity: string;
    unit_price: number;
    income_account_id: number | null;
    tax_rate_id: number | null;
    item_id: number | null;
}

/** Shapes every row for submission — the item picker's own id travels along only as an optional hint. */
export function buildInvoiceLinePayload(rows: InvoiceLineRow[]): InvoiceLinePayload[] {
    return rows.map((row) => ({
        description: row.description.trim(),
        quantity: row.quantityText.trim(),
        unit_price: parseMoneyInput(row.unitPriceText) ?? 0,
        income_account_id: row.incomeAccountId,
        tax_rate_id: row.taxRateId,
        item_id: row.itemId,
    }));
}

/** A single row's live subtotal/tax, mirroring InvoiceCalculator's per-line computation. */
export function computeLineAmounts(
    row: InvoiceLineRow,
    taxRates: TaxRateOption[],
): { lineSubtotal: number; lineTax: number } {
    const unitPrice = parseMoneyInput(row.unitPriceText) ?? 0;
    const lineSubtotal = multiplyMinorByQuantity(unitPrice, row.quantityText) ?? 0;
    const taxRate =
        row.taxRateId === null ? null : (taxRates.find((t) => t.id === row.taxRateId) ?? null);
    const lineTax = taxRate === null ? 0 : computeLineTax(lineSubtotal, taxRate.rate);

    return { lineSubtotal, lineTax };
}

export interface InvoiceTotals {
    subtotal: number;
    taxTotal: number;
    total: number;
}

/** The document-level live preview, mirroring InvoiceCalculator::compute's document totals. */
export function computeInvoiceTotals(rows: InvoiceLineRow[], taxRates: TaxRateOption[]): InvoiceTotals {
    let subtotal = 0;
    let taxTotal = 0;

    for (const row of rows) {
        const { lineSubtotal, lineTax } = computeLineAmounts(row, taxRates);
        subtotal += lineSubtotal;
        taxTotal += lineTax;
    }

    return { subtotal, taxTotal, total: subtotal + taxTotal };
}

const CONTROL_CLASSES =
    'h-9 w-full rounded-md border bg-paper-raised px-3 text-sm text-ink placeholder:text-ink-700/50 transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

function controlBorder(hasError: boolean): string {
    return hasError ? 'border-clay' : 'border-rule';
}

const GRID_COLUMNS =
    'grid grid-cols-[minmax(0,2.2fr)_90px_120px_minmax(0,1.4fr)_minmax(0,1.1fr)_112px_32px] items-start gap-2';

export interface InvoiceLineEditorProps {
    rows: InvoiceLineRow[];
    items: ItemOption[];
    incomeAccounts: IncomeAccountOption[];
    taxRates: TaxRateOption[];
    currency: string;
    onChange: (rows: InvoiceLineRow[]) => void;
    /** Flat error map keyed like the server's session errors, e.g. "lines.0.income_account_id". */
    errors?: Record<string, string | undefined>;
}

/**
 * The invoice line grid: an optional "fill from item" picker per row that
 * prefills description/price/account/tax (client-only — never itself sent to
 * the server), quantity, unit price, income account, tax rate, and a live
 * per-line total. Amount/Tax/Total labels only — this is a customer-facing
 * screen, never debit/credit (PROJECT_MAP §9 rule 8).
 */
export default function InvoiceLineEditor({
    rows,
    items,
    incomeAccounts,
    taxRates,
    currency,
    onChange,
    errors = {},
}: InvoiceLineEditorProps) {
    function updateRow(index: number, patch: Partial<InvoiceLineRow>) {
        const next = rows.slice();
        next[index] = { ...next[index], ...patch };
        onChange(next);
    }

    function applyItem(index: number, itemId: number | null) {
        const item = itemId === null ? null : (items.find((candidate) => candidate.id === itemId) ?? null);

        if (item === null) {
            updateRow(index, { itemId: null });
            return;
        }

        updateRow(index, {
            itemId: item.id,
            description: item.description ?? item.name,
            unitPriceText: minorToDecimalString(item.unit_price),
            incomeAccountId: item.default_income_account_id,
            taxRateId: item.default_tax_rate_id,
        });
    }

    function addRow() {
        onChange([...rows, emptyInvoiceLineRow()]);
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
                <span className="text-right">Qty</span>
                <span className="text-right">Unit price</span>
                <span>Income account</span>
                <span>Tax rate</span>
                <span className="text-right">Line total</span>
                <span className="sr-only">Remove</span>
            </div>

            {rows.map((row, index) => {
                const { lineSubtotal, lineTax } = computeLineAmounts(row, taxRates);
                const descriptionError = errors[`lines.${index}.description`];
                const quantityError = errors[`lines.${index}.quantity`];
                const unitPriceError = errors[`lines.${index}.unit_price`];
                const incomeAccountError = errors[`lines.${index}.income_account_id`];
                const taxRateError = errors[`lines.${index}.tax_rate_id`];

                return (
                    <div
                        key={row.key}
                        className={`${GRID_COLUMNS} border-b border-rule py-2 last:border-b-0`}
                    >
                        <div className="flex flex-col gap-1">
                            {items.length > 0 && (
                                <select
                                    aria-label={`Fill from item, line ${index + 1}`}
                                    value={row.itemId ?? ''}
                                    onChange={(event) =>
                                        applyItem(
                                            index,
                                            event.target.value === '' ? null : Number(event.target.value),
                                        )
                                    }
                                    className="h-7 rounded-md border border-rule bg-paper px-2 text-xs text-ink-700 focus:outline-none focus:ring-2 focus:ring-spark"
                                >
                                    <option value="">Fill from item…</option>
                                    {items.map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.name}
                                        </option>
                                    ))}
                                </select>
                            )}
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
                            <input
                                type="text"
                                inputMode="decimal"
                                aria-label={`Quantity, line ${index + 1}`}
                                aria-invalid={Boolean(quantityError)}
                                value={row.quantityText}
                                onChange={(event) => updateRow(index, { quantityText: event.target.value })}
                                className={`${CONTROL_CLASSES} text-right font-mono tabular-nums ${controlBorder(Boolean(quantityError))}`}
                            />
                            {quantityError && (
                                <p role="alert" className="mt-1 text-right text-xs text-clay">
                                    {quantityError}
                                </p>
                            )}
                        </div>

                        <div>
                            <input
                                type="text"
                                inputMode="decimal"
                                aria-label={`Unit price, line ${index + 1}`}
                                aria-invalid={Boolean(unitPriceError)}
                                placeholder="0.00"
                                value={row.unitPriceText}
                                onChange={(event) => updateRow(index, { unitPriceText: event.target.value })}
                                className={`${CONTROL_CLASSES} text-right font-mono tabular-nums ${controlBorder(Boolean(unitPriceError))}`}
                            />
                            {unitPriceError && (
                                <p role="alert" className="mt-1 text-right text-xs text-clay">
                                    {unitPriceError}
                                </p>
                            )}
                        </div>

                        <div>
                            <select
                                aria-label={`Income account, line ${index + 1}`}
                                aria-invalid={Boolean(incomeAccountError)}
                                value={row.incomeAccountId ?? ''}
                                onChange={(event) =>
                                    updateRow(index, {
                                        incomeAccountId:
                                            event.target.value === '' ? null : Number(event.target.value),
                                    })
                                }
                                className={`${CONTROL_CLASSES} ${controlBorder(Boolean(incomeAccountError))}`}
                            >
                                <option value="">Select…</option>
                                {incomeAccounts.map((account) => (
                                    <option key={account.id} value={account.id}>
                                        {account.code} — {account.name}
                                    </option>
                                ))}
                            </select>
                            {incomeAccountError && (
                                <p role="alert" className="mt-1 text-xs text-clay">
                                    {incomeAccountError}
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

                        <MoneyText minor={lineSubtotal + lineTax} currency={currency} size="sm" className="mt-2" />

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
