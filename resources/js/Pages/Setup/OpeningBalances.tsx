import Button from '@/Components/ui/Button';
import Field from '@/Components/ui/Field';
import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { minorToDecimalString, parseMoneyInput } from '@/lib/money';
import { PageProps } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useRef, useState } from 'react';

interface BankAccountOption {
    id: number;
    name: string;
    currency_code: string;
}

interface ContactOption {
    id: number;
    name: string;
}

interface ActiveRunSummary {
    id: number;
    books_start_date: string;
    customer_count: number;
    vendor_count: number;
    total_receivable: number;
    total_payable: number;
}

interface OpeningBalancesPageProps extends PageProps {
    bankAccounts: BankAccountOption[];
    customers: ContactOption[];
    vendors: ContactOption[];
    booksStartDate: string | null;
    activeRun: ActiveRunSummary | null;
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

interface BankLineRow {
    key: string;
    bank_account_id: string;
    amount: string;
}

interface ContactLineRow {
    key: string;
    contact_id: string;
    reference: string;
    original_date: string;
    due_date: string;
    amount: string;
}

function newKey(): string {
    return crypto.randomUUID();
}

interface CsvRowError {
    line: number;
    message: string;
}

interface ParsedBankLine {
    bank_account_id: number;
    amount: number;
}

interface ParsedContactLine {
    contact_id: number;
    reference: string | null;
    original_date: string;
    due_date: string;
    amount: number;
}

/**
 * Download-template link + "Upload CSV" control for one opening-balances CSV
 * template (spec §17.7). Uploads go straight to OpeningCsvController@import,
 * which validates and parses but never posts — a success hands parsed rows
 * back to `onImported` for the caller to merge into its own line list, a
 * failure surfaces the line-numbered errors right here so the user can fix
 * the file and re-upload without losing anything already on the screen.
 */
function CsvImportControls({ kind, onImported }: { kind: string; onImported: (rows: Record<string, unknown>[]) => void }) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    const [errors, setErrors] = useState<CsvRowError[]>([]);

    const handleFile = async (file: File) => {
        setUploading(true);
        setErrors([]);

        try {
            const formData = new FormData();
            formData.append('file', file);

            const response = await window.axios.post(route('setup.opening-balances.import', kind), formData);
            const { rows, errors: rowErrors } = response.data as {
                rows: Record<string, unknown>[];
                errors: CsvRowError[];
            };

            if (rowErrors.length > 0) {
                setErrors(rowErrors);

                return;
            }

            onImported(rows);
        } catch {
            setErrors([{ line: 0, message: "Something went wrong reading that file — check it's a CSV and try again." }]);
        } finally {
            setUploading(false);
        }
    };

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-center gap-2">
                <a
                    href={route('setup.opening-balances.template', kind)}
                    className="inline-flex h-8 items-center justify-center gap-2 rounded-lg px-3 font-sans text-xs font-semibold text-ink-700 transition duration-[120ms] ease-out hover:bg-paper"
                >
                    Download template
                </a>
                <Button type="button" variant="quiet" size="sm" disabled={uploading} onClick={() => inputRef.current?.click()}>
                    {uploading ? 'Uploading…' : 'Upload CSV'}
                </Button>
                <input
                    ref={inputRef}
                    type="file"
                    accept=".csv,text/csv"
                    className="hidden"
                    onChange={(event) => {
                        const file = event.target.files?.[0];
                        event.target.value = '';
                        if (file) {
                            void handleFile(file);
                        }
                    }}
                />
            </div>
            {errors.length > 0 && (
                <ul role="alert" className="flex flex-col gap-1 rounded-md bg-clay-50 px-3 py-2 text-sm text-clay">
                    {errors.map((error, index) => (
                        <li key={index}>{error.message}</li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function emptyBankLine(): BankLineRow {
    return { key: newKey(), bank_account_id: '', amount: '' };
}

function emptyContactLine(): ContactLineRow {
    return {
        key: newKey(),
        contact_id: '',
        reference: '',
        original_date: todayIsoDate(),
        due_date: todayIsoDate(),
        amount: '',
    };
}

interface OpeningBalancesFormData {
    books_start_date: string;
    bank_lines: BankLineRow[];
    customer_lines: ContactLineRow[];
    vendor_lines: ContactLineRow[];
}

function sumAmounts(rows: { amount: string }[]): number {
    return rows.reduce((sum, row) => sum + (parseMoneyInput(row.amount) ?? 0), 0);
}

/** One repeatable customer-invoice or vendor-bill row: contact + reference on
 * top, dates + amount + remove below. Shared between the customer and vendor
 * sections since the shape (P8b/P8c) is identical — only the contact list and
 * copy differ. */
function ContactLineFields({
    field,
    rows,
    options,
    optionLabel,
    referencePlaceholder,
    errors,
    onUpdate,
    onRemove,
}: {
    field: 'customer_lines' | 'vendor_lines';
    rows: ContactLineRow[];
    options: ContactOption[];
    optionLabel: string;
    referencePlaceholder: string;
    errors: Record<string, string | undefined>;
    onUpdate: (key: string, patch: Partial<ContactLineRow>) => void;
    onRemove: (key: string) => void;
}) {
    return (
        <div className="flex flex-col gap-4">
            {rows.map((row, index) => (
                <div
                    key={row.key}
                    className="flex flex-col gap-3 rounded-md border border-rule p-3"
                >
                    <div className="grid grid-cols-2 gap-3">
                        <Field
                            label={optionLabel}
                            htmlFor={`${field}-contact-${row.key}`}
                            error={errors[`${field}.${index}.contact_id`]}
                        >
                            <select
                                id={`${field}-contact-${row.key}`}
                                value={row.contact_id}
                                onChange={(event) =>
                                    onUpdate(row.key, { contact_id: event.target.value })
                                }
                                className={INPUT_CLASSES}
                            >
                                <option value="">Select {optionLabel.toLowerCase()}</option>
                                {options.map((option) => (
                                    <option key={option.id} value={option.id}>
                                        {option.name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field
                            label="Reference"
                            htmlFor={`${field}-ref-${row.key}`}
                            error={errors[`${field}.${index}.reference`]}
                        >
                            <input
                                id={`${field}-ref-${row.key}`}
                                type="text"
                                value={row.reference}
                                onChange={(event) =>
                                    onUpdate(row.key, { reference: event.target.value })
                                }
                                placeholder={referencePlaceholder}
                                className={INPUT_CLASSES}
                            />
                        </Field>
                    </div>
                    <div className="grid grid-cols-[1fr_1fr_1fr_auto] items-end gap-3">
                        <Field
                            label="Original date"
                            htmlFor={`${field}-orig-${row.key}`}
                            error={errors[`${field}.${index}.original_date`]}
                        >
                            <input
                                id={`${field}-orig-${row.key}`}
                                type="date"
                                value={row.original_date}
                                onChange={(event) =>
                                    onUpdate(row.key, { original_date: event.target.value })
                                }
                                className={INPUT_CLASSES}
                            />
                        </Field>
                        <Field
                            label="Due date"
                            htmlFor={`${field}-due-${row.key}`}
                            error={errors[`${field}.${index}.due_date`]}
                        >
                            <input
                                id={`${field}-due-${row.key}`}
                                type="date"
                                value={row.due_date}
                                onChange={(event) =>
                                    onUpdate(row.key, { due_date: event.target.value })
                                }
                                className={INPUT_CLASSES}
                            />
                        </Field>
                        <Field
                            label="Amount"
                            htmlFor={`${field}-amount-${row.key}`}
                            error={errors[`${field}.${index}.amount`]}
                        >
                            <input
                                id={`${field}-amount-${row.key}`}
                                type="text"
                                inputMode="decimal"
                                placeholder="0.00"
                                value={row.amount}
                                onChange={(event) =>
                                    onUpdate(row.key, { amount: event.target.value })
                                }
                                className={`${INPUT_CLASSES} text-right font-mono tabular-nums`}
                            />
                        </Field>
                        <Button
                            type="button"
                            variant="quiet"
                            size="sm"
                            onClick={() => onRemove(row.key)}
                        >
                            Remove
                        </Button>
                    </div>
                </div>
            ))}
            {options.length === 0 && (
                <p className="text-sm text-ink-700">
                    Add a {optionLabel.toLowerCase()} contact first, then come back here.
                </p>
            )}
        </div>
    );
}

export default function OpeningBalances({
    bankAccounts,
    customers,
    vendors,
    booksStartDate,
    activeRun,
}: OpeningBalancesPageProps) {
    const { currentCompany } = usePage<OpeningBalancesPageProps>().props;
    const currency = currentCompany?.base_currency ?? 'PKR';

    // One idempotency key per "attempt" — regenerated after a successful save
    // so a further re-enter never risks colliding with the just-completed run.
    const [idempotencyKey, setIdempotencyKey] = useState(() => crypto.randomUUID());
    // When an active run already exists, start collapsed to a summary — the
    // full form (which resets everything on submit) only appears once the
    // user explicitly asks to re-enter.
    const [reentering, setReentering] = useState(activeRun === null);

    const form = useForm<OpeningBalancesFormData>({
        books_start_date: booksStartDate ?? todayIsoDate(),
        bank_lines: [emptyBankLine()],
        customer_lines: [emptyContactLine()],
        vendor_lines: [emptyContactLine()],
    });
    const errors = form.errors as Record<string, string | undefined>;

    const bankTotal = sumAmounts(form.data.bank_lines);
    const customerTotal = sumAmounts(form.data.customer_lines);
    const vendorTotal = sumAmounts(form.data.vendor_lines);
    const netDifference = bankTotal + customerTotal - vendorTotal;

    const addBankLine = () => form.setData('bank_lines', [...form.data.bank_lines, emptyBankLine()]);
    const removeBankLine = (key: string) =>
        form.setData('bank_lines', form.data.bank_lines.filter((row) => row.key !== key));
    const updateBankLine = (key: string, patch: Partial<BankLineRow>) =>
        form.setData(
            'bank_lines',
            form.data.bank_lines.map((row) => (row.key === key ? { ...row, ...patch } : row)),
        );

    const addContactLine = (field: 'customer_lines' | 'vendor_lines') =>
        form.setData(field, [...form.data[field], emptyContactLine()]);
    const removeContactLine = (field: 'customer_lines' | 'vendor_lines', key: string) =>
        form.setData(field, form.data[field].filter((row) => row.key !== key));
    const updateContactLine = (
        field: 'customer_lines' | 'vendor_lines',
        key: string,
        patch: Partial<ContactLineRow>,
    ) =>
        form.setData(
            field,
            form.data[field].map((row) => (row.key === key ? { ...row, ...patch } : row)),
        );

    /** Merges server-parsed bank_balances CSV rows into bank_lines: any
     * still-untouched placeholder rows are dropped first so a fresh form
     * doesn't end up with a dangling blank row after the import. Rows the
     * user already filled in by hand are kept, with the imported rows
     * appended after them for review before submit. */
    const importBankLines = (rows: Record<string, unknown>[]) => {
        const parsed = rows as unknown as ParsedBankLine[];
        const imported: BankLineRow[] = parsed.map((row) => ({
            key: newKey(),
            bank_account_id: String(row.bank_account_id),
            amount: minorToDecimalString(row.amount),
        }));
        const kept = form.data.bank_lines.filter((row) => row.bank_account_id !== '' || row.amount !== '');
        form.setData('bank_lines', [...kept, ...imported]);
    };

    const importContactLines = (field: 'customer_lines' | 'vendor_lines', rows: Record<string, unknown>[]) => {
        const parsed = rows as unknown as ParsedContactLine[];
        const imported: ContactLineRow[] = parsed.map((row) => ({
            key: newKey(),
            contact_id: String(row.contact_id),
            reference: row.reference ?? '',
            original_date: row.original_date,
            due_date: row.due_date,
            amount: minorToDecimalString(row.amount),
        }));
        const kept = form.data[field].filter((row) => row.contact_id !== '' || row.amount !== '');
        form.setData(field, [...kept, ...imported]);
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.transform((data) => ({
            books_start_date: data.books_start_date,
            bank_lines: data.bank_lines
                .filter((row) => row.bank_account_id !== '' && row.amount !== '')
                .map((row) => ({
                    bank_account_id: Number(row.bank_account_id),
                    amount: parseMoneyInput(row.amount) ?? 0,
                })),
            customer_lines: data.customer_lines
                .filter((row) => row.contact_id !== '' && row.amount !== '')
                .map((row) => ({
                    contact_id: Number(row.contact_id),
                    reference: row.reference === '' ? null : row.reference,
                    original_date: row.original_date,
                    due_date: row.due_date,
                    amount: parseMoneyInput(row.amount) ?? 0,
                })),
            vendor_lines: data.vendor_lines
                .filter((row) => row.contact_id !== '' && row.amount !== '')
                .map((row) => ({
                    contact_id: Number(row.contact_id),
                    reference: row.reference === '' ? null : row.reference,
                    original_date: row.original_date,
                    due_date: row.due_date,
                    amount: parseMoneyInput(row.amount) ?? 0,
                })),
        }));

        form.post(route('setup.opening-balances.store'), {
            headers: { 'X-Idempotency-Key': idempotencyKey },
            preserveScroll: true,
            onSuccess: () => {
                // The redirect target is this same route/component, so Inertia
                // re-renders the existing instance in place rather than
                // mounting a fresh one — local state (this form, `reentering`)
                // would otherwise keep showing the just-submitted values
                // instead of snapping back to the new active-run summary.
                setIdempotencyKey(crypto.randomUUID());
                setReentering(false);
                form.reset();
            },
        });
    };

    return (
        <AppShell title="Opening balances">
            <Head title="Opening balances" />

            <div className="flex max-w-4xl flex-col gap-6">
                {activeRun && !reentering && (
                    <Sheet title="Opening balances are set up">
                        <div className="flex flex-col gap-4">
                            <p className="text-sm text-ink-700">
                                Your books start on{' '}
                                <span className="font-semibold text-ink">
                                    {activeRun.books_start_date}
                                </span>
                                .{' '}
                                {activeRun.customer_count > 0 && (
                                    <>
                                        Customers owe you{' '}
                                        <MoneyText
                                            minor={activeRun.total_receivable}
                                            currency={currency}
                                            size="sm"
                                            className="inline"
                                        />{' '}
                                        across {activeRun.customer_count} invoice
                                        {activeRun.customer_count === 1 ? '' : 's'}.{' '}
                                    </>
                                )}
                                {activeRun.vendor_count > 0 && (
                                    <>
                                        You owe vendors{' '}
                                        <MoneyText
                                            minor={activeRun.total_payable}
                                            currency={currency}
                                            size="sm"
                                            className="inline"
                                        />{' '}
                                        across {activeRun.vendor_count} bill
                                        {activeRun.vendor_count === 1 ? '' : 's'}.
                                    </>
                                )}
                            </p>
                            <div>
                                <Button variant="quiet" onClick={() => setReentering(true)}>
                                    Re-enter opening balances
                                </Button>
                            </div>
                        </div>
                    </Sheet>
                )}

                {(reentering || !activeRun) && (
                    <form onSubmit={submit} className="flex flex-col gap-6">
                        {errors.idempotency_key && (
                            <p
                                role="alert"
                                className="rounded-md bg-clay-50 px-3 py-2 text-sm text-clay"
                            >
                                {errors.idempotency_key}
                            </p>
                        )}
                        {errors.books_start_date && (
                            <p
                                role="alert"
                                className="rounded-md bg-clay-50 px-3 py-2 text-sm text-clay"
                            >
                                {errors.books_start_date}
                            </p>
                        )}
                        {errors.bank_lines && (
                            <p
                                role="alert"
                                className="rounded-md bg-clay-50 px-3 py-2 text-sm text-clay"
                            >
                                {errors.bank_lines}
                            </p>
                        )}

                        {activeRun && (
                            <p className="rounded-md bg-amber-50 px-3 py-2 text-sm text-ink-700">
                                Saving replaces your current opening balances — the old entries are
                                reversed out, never edited, so your history stays clean.
                            </p>
                        )}

                        <Sheet title="When do your books start?">
                            <Field
                                label="Books start date"
                                htmlFor="books-start-date"
                                error={errors.books_start_date}
                            >
                                <input
                                    id="books-start-date"
                                    type="date"
                                    value={form.data.books_start_date}
                                    onChange={(event) =>
                                        form.setData('books_start_date', event.target.value)
                                    }
                                    className={`${INPUT_CLASSES} max-w-xs`}
                                />
                            </Field>
                        </Sheet>

                        <Sheet
                            title="Bank & cash balances"
                            actions={
                                <Button type="button" variant="quiet" size="sm" onClick={addBankLine}>
                                    Add account
                                </Button>
                            }
                        >
                            <div className="flex flex-col gap-4">
                                <CsvImportControls kind="bank_balances" onImported={importBankLines} />
                                <div className="flex flex-col gap-3">
                                    {form.data.bank_lines.map((row, index) => (
                                        <div
                                            key={row.key}
                                            className="grid grid-cols-[2fr_1fr_auto] items-end gap-3"
                                        >
                                            <Field
                                                label="Account"
                                                htmlFor={`bank-account-${row.key}`}
                                                error={errors[`bank_lines.${index}.bank_account_id`]}
                                            >
                                                <select
                                                    id={`bank-account-${row.key}`}
                                                    value={row.bank_account_id}
                                                    onChange={(event) =>
                                                        updateBankLine(row.key, {
                                                            bank_account_id: event.target.value,
                                                        })
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
                                                label="Balance"
                                                htmlFor={`bank-amount-${row.key}`}
                                                error={errors[`bank_lines.${index}.amount`]}
                                            >
                                                <input
                                                    id={`bank-amount-${row.key}`}
                                                    type="text"
                                                    inputMode="decimal"
                                                    placeholder="0.00"
                                                    value={row.amount}
                                                    onChange={(event) =>
                                                        updateBankLine(row.key, {
                                                            amount: event.target.value,
                                                        })
                                                    }
                                                    className={`${INPUT_CLASSES} text-right font-mono tabular-nums`}
                                                />
                                            </Field>
                                            <Button
                                                type="button"
                                                variant="quiet"
                                                size="sm"
                                                onClick={() => removeBankLine(row.key)}
                                            >
                                                Remove
                                            </Button>
                                        </div>
                                    ))}
                                    {bankAccounts.length === 0 && (
                                        <p className="text-sm text-ink-700">
                                            Add a bank or cash account first, then come back here.
                                        </p>
                                    )}
                                </div>
                            </div>
                        </Sheet>

                        <Sheet
                            title="Customers who owe you money"
                            actions={
                                <Button
                                    type="button"
                                    variant="quiet"
                                    size="sm"
                                    onClick={() => addContactLine('customer_lines')}
                                >
                                    Add invoice
                                </Button>
                            }
                        >
                            <div className="flex flex-col gap-4">
                                <CsvImportControls
                                    kind="open_customer_invoices"
                                    onImported={(rows) => importContactLines('customer_lines', rows)}
                                />
                                <ContactLineFields
                                    field="customer_lines"
                                    rows={form.data.customer_lines}
                                    options={customers}
                                    optionLabel="Customer"
                                    referencePlaceholder="Invoice #"
                                    errors={errors}
                                    onUpdate={(key, patch) =>
                                        updateContactLine('customer_lines', key, patch)
                                    }
                                    onRemove={(key) => removeContactLine('customer_lines', key)}
                                />
                            </div>
                        </Sheet>

                        <Sheet
                            title="Vendors you owe money to"
                            actions={
                                <Button
                                    type="button"
                                    variant="quiet"
                                    size="sm"
                                    onClick={() => addContactLine('vendor_lines')}
                                >
                                    Add bill
                                </Button>
                            }
                        >
                            <div className="flex flex-col gap-4">
                                <CsvImportControls
                                    kind="open_vendor_bills"
                                    onImported={(rows) => importContactLines('vendor_lines', rows)}
                                />
                                <ContactLineFields
                                    field="vendor_lines"
                                    rows={form.data.vendor_lines}
                                    options={vendors}
                                    optionLabel="Vendor"
                                    referencePlaceholder="Bill #"
                                    errors={errors}
                                    onUpdate={(key, patch) =>
                                        updateContactLine('vendor_lines', key, patch)
                                    }
                                    onRemove={(key) => removeContactLine('vendor_lines', key)}
                                />
                            </div>
                        </Sheet>

                        <Sheet title="Opening Balance Equity">
                            <div className="flex flex-col gap-2">
                                <div className="flex items-center justify-between text-sm text-ink-700">
                                    <span>You have</span>
                                    <MoneyText minor={bankTotal} currency={currency} size="sm" />
                                </div>
                                <div className="flex items-center justify-between text-sm text-ink-700">
                                    <span>Customers owe you</span>
                                    <MoneyText minor={customerTotal} currency={currency} size="sm" />
                                </div>
                                <div className="flex items-center justify-between text-sm text-ink-700">
                                    <span>You owe vendors</span>
                                    <MoneyText minor={vendorTotal} currency={currency} size="sm" />
                                </div>
                                <div className="mt-2 flex items-center justify-between border-t border-rule pt-3">
                                    <span className="font-sans text-sm font-semibold text-ink">
                                        Opening Balance Equity
                                    </span>
                                    <MoneyText
                                        minor={netDifference}
                                        currency={currency}
                                        size="md"
                                        className="font-semibold"
                                    />
                                </div>
                                <p className="mt-1 text-xs text-ink-700">
                                    This temporary account holds the difference until you&apos;re
                                    ready to move it to Owner&apos;s Equity — you can do that
                                    anytime from the Chart of Accounts.
                                </p>
                            </div>
                        </Sheet>

                        <div className="flex justify-end gap-3">
                            {activeRun && (
                                <Button
                                    type="button"
                                    variant="quiet"
                                    onClick={() => setReentering(false)}
                                >
                                    Cancel
                                </Button>
                            )}
                            <Button type="submit" disabled={form.processing}>
                                {form.processing ? 'Saving…' : 'Save opening balances'}
                            </Button>
                        </div>
                    </form>
                )}
            </div>
        </AppShell>
    );
}
