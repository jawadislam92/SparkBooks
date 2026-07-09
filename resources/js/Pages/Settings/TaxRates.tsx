import Button from '@/Components/ui/Button';
import Field from '@/Components/ui/Field';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import {
    Dialog,
    DialogBackdrop,
    DialogPanel,
    DialogTitle,
} from '@headlessui/react';
import { FormEventHandler, useMemo, useState } from 'react';

interface TaxRateRow {
    id: number;
    name: string;
    /** Decimal string straight from the DECIMAL(7,4) column, e.g. "18.0000". */
    rate: string;
    is_archived: boolean;
}

interface IndexPageProps extends PageProps {
    taxRates: TaxRateRow[];
}

const INPUT_CLASSES =
    'h-10 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

/**
 * "18.0000" -> "18%", "17.5000" -> "17.5%". Purely string trimming of the
 * decimal cast's trailing zeros — no numeric parsing, so this can never
 * introduce float rounding into a value that's only ever displayed.
 */
function formatRatePercent(rate: string): string {
    const trimmed = rate.includes('.')
        ? rate.replace(/0+$/, '').replace(/\.$/, '')
        : rate;

    return `${trimmed}%`;
}

function ArchivedTag() {
    return (
        <span className="ml-2 inline-block rounded-full bg-paper px-2 py-0.5 text-xs font-medium text-ink-700">
            Archived
        </span>
    );
}

interface TaxRateFormData {
    name: string;
    rate: string;
}

function TaxRateFormDialog({
    taxRate,
    onClose,
}: {
    /** Present => editing; absent => creating a new tax rate. */
    taxRate: TaxRateRow | null;
    onClose: () => void;
}) {
    const isEditing = taxRate !== null;

    const form = useForm<TaxRateFormData>({
        name: taxRate?.name ?? '',
        rate: taxRate?.rate ?? '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        if (isEditing) {
            form.patch(route('tax-rates.update', taxRate.id), {
                preserveScroll: true,
                onSuccess: onClose,
            });
        } else {
            form.post(route('tax-rates.store'), {
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
                        {isEditing ? 'Edit tax rate' : 'New tax rate'}
                    </DialogTitle>

                    <form
                        onSubmit={submit}
                        className="mt-4 flex flex-col gap-4"
                    >
                        <Field
                            label="Name"
                            htmlFor="tax-rate-name"
                            error={errors.name}
                        >
                            <input
                                id="tax-rate-name"
                                type="text"
                                placeholder="e.g. GST 18%"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                className={INPUT_CLASSES}
                            />
                        </Field>

                        <Field
                            label="Rate (%)"
                            htmlFor="tax-rate-rate"
                            error={errors.rate}
                        >
                            <input
                                id="tax-rate-rate"
                                type="text"
                                inputMode="decimal"
                                placeholder="18"
                                value={form.data.rate}
                                onChange={(event) =>
                                    form.setData('rate', event.target.value)
                                }
                                className={INPUT_CLASSES}
                            />
                        </Field>

                        <p className="text-xs text-ink-700">
                            Editing a rate only changes what new documents
                            use — it never changes a document that already
                            exists, since the rate is copied onto each line
                            the moment it&rsquo;s added.
                        </p>

                        <div className="mt-2 flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="quiet"
                                onClick={onClose}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing
                                    ? 'Saving…'
                                    : isEditing
                                      ? 'Save changes'
                                      : 'Create tax rate'}
                            </Button>
                        </div>
                    </form>
                </DialogPanel>
            </div>
        </Dialog>
    );
}

export default function TaxRates({ taxRates }: IndexPageProps) {
    const [showArchived, setShowArchived] = useState(false);
    const [editingTaxRate, setEditingTaxRate] = useState<TaxRateRow | null>(
        null,
    );
    const [creating, setCreating] = useState(false);

    const visibleTaxRates = useMemo(
        () => taxRates.filter((t) => showArchived || !t.is_archived),
        [taxRates, showArchived],
    );

    function toggleArchive(taxRate: TaxRateRow) {
        const routeName = taxRate.is_archived
            ? 'tax-rates.unarchive'
            : 'tax-rates.archive';
        router.post(route(routeName, taxRate.id), {}, { preserveScroll: true });
    }

    return (
        <AppShell title="Tax rates">
            <Head title="Tax rates" />

            <div className="mb-4 flex items-center justify-between gap-4">
                <label className="flex items-center gap-2 text-sm text-ink-700">
                    <input
                        type="checkbox"
                        checked={showArchived}
                        onChange={(event) =>
                            setShowArchived(event.target.checked)
                        }
                        className="h-4 w-4 rounded border-rule text-spark focus:ring-spark"
                    />
                    Show archived
                </label>
                <Button type="button" onClick={() => setCreating(true)}>
                    New tax rate
                </Button>
            </div>

            <Sheet>
                <p className="mb-4 text-sm text-ink-700">
                    Tax rates are reference data: the rate is copied onto
                    each document line when it&rsquo;s added, so editing a
                    rate here never changes a document that already exists.
                </p>

                {visibleTaxRates.length === 0 ? (
                    <p className="py-8 text-center text-sm text-ink-700">
                        {taxRates.length === 0
                            ? 'No tax rates yet.'
                            : 'No tax rates match this filter.'}
                    </p>
                ) : (
                    <table className="w-full border-collapse text-left text-sm">
                        <thead>
                            <tr className="border-b border-rule text-xs font-medium uppercase tracking-wide text-ink-700">
                                <th scope="col" className="py-2 pr-3 font-medium">
                                    Name
                                </th>
                                <th scope="col" className="py-2 pr-3 font-medium">
                                    Rate
                                </th>
                                <th scope="col" className="py-2 pl-3 font-medium">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {visibleTaxRates.map((taxRate) => (
                                <tr
                                    key={taxRate.id}
                                    className={
                                        `border-b border-rule text-ink last:border-b-0 ` +
                                        (taxRate.is_archived
                                            ? 'opacity-50'
                                            : '')
                                    }
                                >
                                    <td className="py-3 pr-3 align-top">
                                        <span className="inline-flex items-center gap-1.5">
                                            {taxRate.name}
                                            {taxRate.is_archived && (
                                                <ArchivedTag />
                                            )}
                                        </span>
                                    </td>
                                    <td className="py-3 pr-3 align-top text-ink-700">
                                        {formatRatePercent(taxRate.rate)}
                                    </td>
                                    <td className="py-3 pl-3 align-top text-right">
                                        <div className="flex justify-end gap-1">
                                            <Button
                                                type="button"
                                                variant="quiet"
                                                size="sm"
                                                onClick={() =>
                                                    setEditingTaxRate(taxRate)
                                                }
                                            >
                                                Edit
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="quiet"
                                                size="sm"
                                                onClick={() =>
                                                    toggleArchive(taxRate)
                                                }
                                            >
                                                {taxRate.is_archived
                                                    ? 'Unarchive'
                                                    : 'Archive'}
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
                <TaxRateFormDialog
                    taxRate={null}
                    onClose={() => setCreating(false)}
                />
            )}
            {editingTaxRate && (
                <TaxRateFormDialog
                    taxRate={editingTaxRate}
                    onClose={() => setEditingTaxRate(null)}
                />
            )}
        </AppShell>
    );
}
