import Button from '@/Components/ui/Button';
import ExportCsvLink from '@/Components/ui/ExportCsvLink';
import Field from '@/Components/ui/Field';
import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { parseMoneyInput } from '@/lib/money';
import { PageProps } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    Dialog,
    DialogBackdrop,
    DialogPanel,
    DialogTitle,
} from '@headlessui/react';
import { FormEventHandler, useMemo, useState } from 'react';

interface IncomeAccountOption {
    id: number;
    code: string;
    name: string;
}

interface TaxRateOption {
    id: number;
    name: string;
    /** Decimal string straight from the DECIMAL(7,4) column, e.g. "18.0000". */
    rate: string;
}

interface ItemRow {
    id: number;
    name: string;
    description: string | null;
    /** Integer minor units (e.g. paisa/cents) — never a float. */
    unit_price: number;
    default_income_account_id: number | null;
    default_income_account_name: string | null;
    default_tax_rate_id: number | null;
    default_tax_rate_name: string | null;
    is_archived: boolean;
}

interface IndexPageProps extends PageProps {
    items: ItemRow[];
    incomeAccounts: IncomeAccountOption[];
    taxRates: TaxRateOption[];
}

const INPUT_CLASSES =
    'h-10 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

function ArchivedTag() {
    return (
        <span className="ml-2 inline-block rounded-full bg-paper px-2 py-0.5 text-xs font-medium text-ink-700">
            Archived
        </span>
    );
}

/**
 * Integer minor units -> plain decimal text for an editable input, e.g.
 * 150050 -> "1500.50". Pure integer division/modulo/string padding — never
 * float math — mirrors parseMoneyInput's own no-float discipline in the
 * other direction.
 */
function minorToInputText(minor: number): string {
    const whole = Math.trunc(minor / 100);
    const fraction = Math.abs(minor % 100);
    return `${whole}.${String(fraction).padStart(2, '0')}`;
}

interface ItemFormData {
    name: string;
    description: string;
    unit_price: string;
    default_income_account_id: string;
    default_tax_rate_id: string;
}

function ItemFormDialog({
    item,
    incomeAccounts,
    taxRates,
    onClose,
}: {
    /** Present => editing; absent => creating a new item. */
    item: ItemRow | null;
    incomeAccounts: IncomeAccountOption[];
    taxRates: TaxRateOption[];
    onClose: () => void;
}) {
    const isEditing = item !== null;

    const form = useForm<ItemFormData>({
        name: item?.name ?? '',
        description: item?.description ?? '',
        unit_price: item ? minorToInputText(item.unit_price) : '',
        default_income_account_id:
            item?.default_income_account_id != null
                ? String(item.default_income_account_id)
                : '',
        default_tax_rate_id:
            item?.default_tax_rate_id != null
                ? String(item.default_tax_rate_id)
                : '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        const priceText = form.data.unit_price.trim();
        const minorPrice = priceText === '' ? 0 : parseMoneyInput(priceText);

        if (minorPrice === null) {
            form.setError('unit_price', 'Enter a valid amount.');
            return;
        }

        form.transform((data) => ({
            name: data.name,
            description: data.description,
            unit_price: minorPrice,
            default_income_account_id:
                data.default_income_account_id === ''
                    ? null
                    : Number(data.default_income_account_id),
            default_tax_rate_id:
                data.default_tax_rate_id === ''
                    ? null
                    : Number(data.default_tax_rate_id),
        }));

        if (isEditing) {
            form.patch(route('items.update', item.id), {
                preserveScroll: true,
                onSuccess: onClose,
            });
        } else {
            form.post(route('items.store'), {
                preserveScroll: true,
                onSuccess: onClose,
            });
        }
    };

    return (
        <Dialog open onClose={onClose} className="relative z-50">
            <DialogBackdrop className="fixed inset-0 bg-ink/30" />
            <div className="fixed inset-0 flex items-center justify-center p-4">
                <DialogPanel className="w-full max-w-md rounded-sheet bg-paper-raised p-6 shadow-sheet">
                    <DialogTitle className="font-display text-lg font-semibold text-ink">
                        {isEditing ? 'Edit item' : 'New item'}
                    </DialogTitle>

                    <form
                        onSubmit={submit}
                        className="mt-4 flex max-h-[70vh] flex-col gap-4 overflow-y-auto"
                    >
                        <Field label="Name" htmlFor="item-name" error={errors.name}>
                            <input
                                id="item-name"
                                type="text"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                className={INPUT_CLASSES}
                            />
                        </Field>

                        <Field
                            label="Description"
                            htmlFor="item-description"
                            error={errors.description}
                        >
                            <textarea
                                id="item-description"
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData('description', event.target.value)
                                }
                                rows={2}
                                className={`${INPUT_CLASSES} h-auto resize-none py-2`}
                            />
                        </Field>

                        <Field
                            label="Default price"
                            htmlFor="item-unit-price"
                            error={errors.unit_price}
                        >
                            <input
                                id="item-unit-price"
                                type="text"
                                inputMode="decimal"
                                placeholder="0.00"
                                value={form.data.unit_price}
                                onChange={(event) =>
                                    form.setData('unit_price', event.target.value)
                                }
                                className={`${INPUT_CLASSES} text-right font-mono tabular-nums`}
                            />
                        </Field>

                        <Field
                            label="Default income account"
                            htmlFor="item-income-account"
                            error={errors.default_income_account_id}
                        >
                            <select
                                id="item-income-account"
                                value={form.data.default_income_account_id}
                                onChange={(event) =>
                                    form.setData(
                                        'default_income_account_id',
                                        event.target.value,
                                    )
                                }
                                className={INPUT_CLASSES}
                            >
                                <option value="">None</option>
                                {incomeAccounts.map((account) => (
                                    <option key={account.id} value={account.id}>
                                        {account.code} — {account.name}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field
                            label="Default tax rate"
                            htmlFor="item-tax-rate"
                            error={errors.default_tax_rate_id}
                        >
                            <select
                                id="item-tax-rate"
                                value={form.data.default_tax_rate_id}
                                onChange={(event) =>
                                    form.setData('default_tax_rate_id', event.target.value)
                                }
                                className={INPUT_CLASSES}
                            >
                                <option value="">None</option>
                                {taxRates.map((taxRate) => (
                                    <option key={taxRate.id} value={taxRate.id}>
                                        {taxRate.name}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <div className="mt-2 flex justify-end gap-2">
                            <Button type="button" variant="quiet" onClick={onClose}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing
                                    ? 'Saving…'
                                    : isEditing
                                      ? 'Save changes'
                                      : 'Create item'}
                            </Button>
                        </div>
                    </form>
                </DialogPanel>
            </div>
        </Dialog>
    );
}

export default function Index({ items, incomeAccounts, taxRates }: IndexPageProps) {
    const { currentCompany, can } = usePage<PageProps>().props;
    const currency = currentCompany?.base_currency ?? 'USD';
    const [showArchived, setShowArchived] = useState(false);
    const [editingItem, setEditingItem] = useState<ItemRow | null>(null);
    const [creating, setCreating] = useState(false);

    const visibleItems = useMemo(
        () => (showArchived ? items : items.filter((item) => !item.is_archived)),
        [items, showArchived],
    );

    function toggleArchive(item: ItemRow) {
        const routeName = item.is_archived ? 'items.unarchive' : 'items.archive';
        router.post(route(routeName, item.id), {}, { preserveScroll: true });
    }

    return (
        <AppShell title="Items">
            <Head title="Items" />

            <div className="mb-4 flex items-center justify-between gap-4">
                <label className="flex items-center gap-2 text-sm text-ink-700">
                    <input
                        type="checkbox"
                        checked={showArchived}
                        onChange={(event) => setShowArchived(event.target.checked)}
                        className="h-4 w-4 rounded border-rule text-spark focus:ring-spark"
                    />
                    Show archived
                </label>
                <div className="flex items-center gap-2">
                    {can['exports.run'] && <ExportCsvLink href={route('items.export')} />}
                    <Button type="button" onClick={() => setCreating(true)}>
                        New item
                    </Button>
                </div>
            </div>

            <Sheet>
                {visibleItems.length === 0 ? (
                    <p className="py-8 text-center text-sm text-ink-700">
                        {items.length === 0
                            ? 'No items yet.'
                            : 'No items match this filter.'}
                    </p>
                ) : (
                    <table className="w-full border-collapse text-left text-sm">
                        <thead>
                            <tr className="border-b border-rule text-xs font-medium uppercase tracking-wide text-ink-700">
                                <th scope="col" className="py-2 pr-3 font-medium">
                                    Name
                                </th>
                                <th scope="col" className="py-2 pr-3 text-right font-medium">
                                    Default price
                                </th>
                                <th scope="col" className="py-2 pr-3 font-medium">
                                    Income account
                                </th>
                                <th scope="col" className="py-2 pr-3 font-medium">
                                    Tax rate
                                </th>
                                <th scope="col" className="py-2 pl-3 font-medium">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {visibleItems.map((item) => (
                                <tr
                                    key={item.id}
                                    className={
                                        `border-b border-rule text-ink last:border-b-0 ` +
                                        (item.is_archived ? 'opacity-50' : '')
                                    }
                                >
                                    <td className="py-3 pr-3 align-top">
                                        <span className="inline-flex items-center gap-1.5">
                                            {item.name}
                                            {item.is_archived && <ArchivedTag />}
                                        </span>
                                    </td>
                                    <td className="py-3 pr-3 align-top">
                                        <MoneyText
                                            minor={item.unit_price}
                                            currency={currency}
                                            size="sm"
                                        />
                                    </td>
                                    <td className="py-3 pr-3 align-top text-ink-700">
                                        {item.default_income_account_name ?? ''}
                                    </td>
                                    <td className="py-3 pr-3 align-top text-ink-700">
                                        {item.default_tax_rate_name ?? ''}
                                    </td>
                                    <td className="py-3 pl-3 align-top text-right">
                                        <div className="flex justify-end gap-1">
                                            <Button
                                                type="button"
                                                variant="quiet"
                                                size="sm"
                                                onClick={() => setEditingItem(item)}
                                            >
                                                Edit
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="quiet"
                                                size="sm"
                                                onClick={() => toggleArchive(item)}
                                            >
                                                {item.is_archived ? 'Unarchive' : 'Archive'}
                                            </Button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Sheet>

            {creating && (
                <ItemFormDialog
                    item={null}
                    incomeAccounts={incomeAccounts}
                    taxRates={taxRates}
                    onClose={() => setCreating(false)}
                />
            )}
            {editingItem && (
                <ItemFormDialog
                    item={editingItem}
                    incomeAccounts={incomeAccounts}
                    taxRates={taxRates}
                    onClose={() => setEditingItem(null)}
                />
            )}
        </AppShell>
    );
}
