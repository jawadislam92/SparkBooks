import Button from '@/Components/ui/Button';
import Field from '@/Components/ui/Field';
import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    Dialog,
    DialogBackdrop,
    DialogPanel,
    DialogTitle,
} from '@headlessui/react';
import { FormEventHandler, useMemo, useState } from 'react';

type BankAccountType = 'bank' | 'cash';

interface BankAccountRow {
    id: number;
    name: string;
    type: BankAccountType;
    currency_code: string;
    is_archived: boolean;
    balance: number;
}

interface IndexPageProps extends PageProps {
    bankAccounts: BankAccountRow[];
}

const TYPE_LABELS: Record<BankAccountType, string> = {
    bank: 'Bank',
    cash: 'Cash',
};

// Bank = lagoon tint, Cash = spark-50 — mirrors the Contacts type-chip pattern.
const TYPE_CHIP_CLASSES: Record<BankAccountType, string> = {
    bank: 'bg-lagoon/10 text-lagoon',
    cash: 'bg-spark-50 text-ink',
};

const INPUT_CLASSES =
    'h-10 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

function TypeChip({ type }: { type: BankAccountType }) {
    return (
        <span
            className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${TYPE_CHIP_CLASSES[type]}`}
        >
            {TYPE_LABELS[type]}
        </span>
    );
}

function ArchivedTag() {
    return (
        <span className="ml-2 inline-block rounded-full bg-paper px-2 py-0.5 text-xs font-medium text-ink-700">
            Archived
        </span>
    );
}

interface CreateFormData {
    name: string;
    type: BankAccountType;
    currency_code: string;
}

function CreateAccountDialog({
    baseCurrency,
    onClose,
}: {
    baseCurrency: string;
    onClose: () => void;
}) {
    const form = useForm<CreateFormData>({
        name: '',
        type: 'bank',
        currency_code: baseCurrency,
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.post(route('bank-accounts.store'), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open onClose={onClose} className="relative z-50">
            <DialogBackdrop className="fixed inset-0 bg-ink/30" />
            <div className="fixed inset-0 flex items-center justify-center p-4">
                <DialogPanel className="w-full max-w-md rounded-sheet bg-paper-raised p-6 shadow-sheet">
                    <DialogTitle className="font-display text-lg font-semibold text-ink">
                        New account
                    </DialogTitle>

                    <form onSubmit={submit} className="mt-4 flex flex-col gap-4">
                        <Field label="Name" htmlFor="bank-account-name" error={errors.name}>
                            <input
                                id="bank-account-name"
                                type="text"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                className={INPUT_CLASSES}
                                autoFocus
                            />
                        </Field>

                        <Field label="Type" htmlFor="bank-account-type" error={errors.type}>
                            <select
                                id="bank-account-type"
                                value={form.data.type}
                                onChange={(event) =>
                                    form.setData('type', event.target.value as BankAccountType)
                                }
                                className={INPUT_CLASSES}
                            >
                                <option value="bank">Bank</option>
                                <option value="cash">Cash</option>
                            </select>
                        </Field>

                        <Field
                            label="Currency"
                            htmlFor="bank-account-currency"
                            error={errors.currency_code}
                        >
                            <select
                                id="bank-account-currency"
                                value={form.data.currency_code}
                                disabled
                                className={`${INPUT_CLASSES} cursor-not-allowed opacity-60`}
                            >
                                <option value={baseCurrency}>{baseCurrency}</option>
                            </select>
                            <p className="text-xs text-ink-700">
                                Bank accounts use your company&apos;s base currency for now.
                            </p>
                        </Field>

                        <div className="mt-2 flex justify-end gap-2">
                            <Button type="button" variant="quiet" onClick={onClose}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing ? 'Creating…' : 'Create account'}
                            </Button>
                        </div>
                    </form>
                </DialogPanel>
            </div>
        </Dialog>
    );
}

function RenameAccountDialog({
    account,
    onClose,
}: {
    account: BankAccountRow;
    onClose: () => void;
}) {
    const form = useForm<{ name: string }>({ name: account.name });
    const errors = form.errors as Record<string, string | undefined>;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.patch(route('bank-accounts.update', account.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open onClose={onClose} className="relative z-50">
            <DialogBackdrop className="fixed inset-0 bg-ink/30" />
            <div className="fixed inset-0 flex items-center justify-center p-4">
                <DialogPanel className="w-full max-w-md rounded-sheet bg-paper-raised p-6 shadow-sheet">
                    <DialogTitle className="font-display text-lg font-semibold text-ink">
                        Rename account
                    </DialogTitle>

                    <form onSubmit={submit} className="mt-4 flex flex-col gap-4">
                        <Field label="Name" htmlFor="bank-account-rename" error={errors.name}>
                            <input
                                id="bank-account-rename"
                                type="text"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                className={INPUT_CLASSES}
                                autoFocus
                            />
                        </Field>

                        <div className="mt-2 flex justify-end gap-2">
                            <Button type="button" variant="quiet" onClick={onClose}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing ? 'Saving…' : 'Save changes'}
                            </Button>
                        </div>
                    </form>
                </DialogPanel>
            </div>
        </Dialog>
    );
}

export default function Index({ bankAccounts }: IndexPageProps) {
    const { currentCompany, can } = usePage<PageProps>().props;
    const baseCurrency = currentCompany?.base_currency ?? 'USD';

    const [showArchived, setShowArchived] = useState(false);
    const [creating, setCreating] = useState(false);
    const [renaming, setRenaming] = useState<BankAccountRow | null>(null);

    const visibleAccounts = useMemo(
        () => bankAccounts.filter((a) => showArchived || !a.is_archived),
        [bankAccounts, showArchived],
    );

    function toggleArchive(account: BankAccountRow) {
        const routeName = account.is_archived
            ? 'bank-accounts.unarchive'
            : 'bank-accounts.archive';
        router.post(route(routeName, account.id), {}, { preserveScroll: true });
    }

    return (
        <AppShell title="Bank & cash accounts">
            <Head title="Bank & cash accounts" />

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
                    {can['other_receipts.create'] && (
                        <Button
                            type="button"
                            variant="quiet"
                            onClick={() => router.visit(route('other-receipts.new'))}
                        >
                            Record receipt
                        </Button>
                    )}
                    {can['transfers.create'] && (
                        <Button
                            type="button"
                            variant="quiet"
                            onClick={() => router.visit(route('transfers.new'))}
                        >
                            Transfer
                        </Button>
                    )}
                    <Button type="button" onClick={() => setCreating(true)}>
                        New account
                    </Button>
                </div>
            </div>

            <Sheet>
                {visibleAccounts.length === 0 ? (
                    <p className="py-8 text-center text-sm text-ink-700">
                        {bankAccounts.length === 0
                            ? 'No bank or cash accounts yet.'
                            : 'No accounts match this filter.'}
                    </p>
                ) : (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {visibleAccounts.map((account) => (
                            <div
                                key={account.id}
                                className={
                                    `flex flex-col gap-3 rounded-md border border-rule p-4 ` +
                                    (account.is_archived ? 'opacity-50' : '')
                                }
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <div>
                                        <p className="font-sans text-sm font-semibold text-ink">
                                            {account.name}
                                            {account.is_archived && <ArchivedTag />}
                                        </p>
                                        <div className="mt-1 flex items-center gap-2">
                                            <TypeChip type={account.type} />
                                            <span className="text-xs text-ink-700">
                                                {account.currency_code}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <MoneyText
                                    minor={account.balance}
                                    currency={account.currency_code}
                                    size="lg"
                                    className="text-left"
                                />

                                <div className="mt-1 flex justify-end gap-1">
                                    <Button
                                        type="button"
                                        variant="quiet"
                                        size="sm"
                                        onClick={() => setRenaming(account)}
                                    >
                                        Rename
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="quiet"
                                        size="sm"
                                        onClick={() => toggleArchive(account)}
                                    >
                                        {account.is_archived ? 'Unarchive' : 'Archive'}
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </Sheet>

            {creating && (
                <CreateAccountDialog
                    baseCurrency={baseCurrency}
                    onClose={() => setCreating(false)}
                />
            )}
            {renaming && (
                <RenameAccountDialog account={renaming} onClose={() => setRenaming(null)} />
            )}
        </AppShell>
    );
}
