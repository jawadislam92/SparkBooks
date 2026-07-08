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

interface AccountRow {
    id: number;
    code: string;
    name: string;
    type: string;
    subtype: string;
    subtype_label: string;
    is_system: boolean;
    is_archived: boolean;
    description: string | null;
    balance: number;
}

interface IndexPageProps extends PageProps {
    accounts: AccountRow[];
}

/** Every subtype the user can choose when creating/editing an account, grouped by the type it derives. */
const SUBTYPE_CATALOG: { value: string; label: string; type: string }[] = [
    { value: 'cash', label: 'Cash', type: 'asset' },
    { value: 'bank', label: 'Bank', type: 'asset' },
    { value: 'receivable', label: 'Money owed to you', type: 'asset' },
    { value: 'fixed_asset', label: 'Fixed asset', type: 'asset' },
    { value: 'current_asset', label: 'Current asset', type: 'asset' },
    { value: 'payable', label: 'Money you owe', type: 'liability' },
    { value: 'tax', label: 'Tax', type: 'liability' },
    { value: 'current_liability', label: 'Current liability', type: 'liability' },
    { value: 'equity', label: 'Equity', type: 'equity' },
    { value: 'operating_income', label: 'Income', type: 'income' },
    { value: 'other_income', label: 'Other income', type: 'income' },
    { value: 'operating_expense', label: 'Expense', type: 'expense' },
];

const TYPE_LABELS: Record<string, string> = {
    asset: 'Assets',
    liability: 'Liabilities',
    equity: 'Equity',
    income: 'Income',
    expense: 'Expenses',
};

const TYPE_ORDER = ['asset', 'liability', 'equity', 'income', 'expense'];

const INPUT_CLASSES =
    'h-10 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

function LockIcon() {
    return (
        <span title="Built-in account" className="inline-flex text-ink-700">
            <span className="sr-only">Built-in account</span>
            <svg
                aria-hidden="true"
                viewBox="0 0 20 20"
                fill="currentColor"
                className="h-3.5 w-3.5"
            >
                <path
                    fillRule="evenodd"
                    d="M10 1a4 4 0 00-4 4v2H5a1 1 0 00-1 1v9a1 1 0 001 1h10a1 1 0 001-1v-9a1 1 0 00-1-1h-1V5a4 4 0 00-4-4zm2 6V5a2 2 0 10-4 0v2h4z"
                    clipRule="evenodd"
                />
            </svg>
        </span>
    );
}

interface AccountFormData {
    code: string;
    name: string;
    subtype: string;
    description: string;
}

function AccountFormDialog({
    account,
    onClose,
}: {
    /** Present => editing; absent => creating a new account. */
    account: AccountRow | null;
    onClose: () => void;
}) {
    const isEditing = account !== null;
    const canChangeCodeAndSubtype = !isEditing || !account.is_system;

    const form = useForm<AccountFormData>({
        code: account?.code ?? '',
        name: account?.name ?? '',
        subtype: account?.subtype ?? '',
        description: account?.description ?? '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        if (isEditing) {
            form.patch(route('accounts.update', account.id), {
                preserveScroll: true,
                onSuccess: onClose,
            });
        } else {
            form.post(route('accounts.store'), {
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
                        {isEditing ? 'Edit account' : 'New account'}
                    </DialogTitle>

                    <form onSubmit={submit} className="mt-4 flex flex-col gap-4">
                        {canChangeCodeAndSubtype && (
                            <Field label="Code" htmlFor="account-code" error={errors.code}>
                                <input
                                    id="account-code"
                                    type="text"
                                    inputMode="numeric"
                                    value={form.data.code}
                                    onChange={(event) => form.setData('code', event.target.value)}
                                    placeholder="1234"
                                    maxLength={4}
                                    className={`${INPUT_CLASSES} font-mono`}
                                />
                            </Field>
                        )}

                        <Field label="Name" htmlFor="account-name" error={errors.name}>
                            <input
                                id="account-name"
                                type="text"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                className={INPUT_CLASSES}
                            />
                        </Field>

                        {canChangeCodeAndSubtype && (
                            <Field label="Type" htmlFor="account-subtype" error={errors.subtype}>
                                <select
                                    id="account-subtype"
                                    value={form.data.subtype}
                                    onChange={(event) => form.setData('subtype', event.target.value)}
                                    className={INPUT_CLASSES}
                                >
                                    <option value="" disabled>
                                        Select a type…
                                    </option>
                                    {TYPE_ORDER.map((type) => (
                                        <optgroup key={type} label={TYPE_LABELS[type]}>
                                            {SUBTYPE_CATALOG.filter((s) => s.type === type).map(
                                                (subtype) => (
                                                    <option key={subtype.value} value={subtype.value}>
                                                        {subtype.label}
                                                    </option>
                                                ),
                                            )}
                                        </optgroup>
                                    ))}
                                </select>
                            </Field>
                        )}

                        <Field
                            label="Description"
                            htmlFor="account-description"
                            error={errors.description}
                        >
                            <textarea
                                id="account-description"
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData('description', event.target.value)
                                }
                                rows={2}
                                className={`${INPUT_CLASSES} h-auto resize-none py-2`}
                            />
                        </Field>

                        {isEditing && account.is_system && (
                            <p className="text-xs text-ink-700">
                                This is a built-in account — only its name and description can
                                change.
                            </p>
                        )}

                        <div className="mt-2 flex justify-end gap-2">
                            <Button type="button" variant="quiet" onClick={onClose}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing
                                    ? 'Saving…'
                                    : isEditing
                                      ? 'Save changes'
                                      : 'Create account'}
                            </Button>
                        </div>
                    </form>
                </DialogPanel>
            </div>
        </Dialog>
    );
}

function ArchivedTag() {
    return (
        <span className="ml-2 inline-block rounded-full bg-paper px-2 py-0.5 text-xs font-medium text-ink-700">
            Archived
        </span>
    );
}

function AccountTypeSheet({
    type,
    accounts,
    currency,
    onEdit,
    onToggleArchive,
}: {
    type: string;
    accounts: AccountRow[];
    currency: string;
    onEdit: (account: AccountRow) => void;
    onToggleArchive: (account: AccountRow) => void;
}) {
    if (accounts.length === 0) {
        return null;
    }

    return (
        <Sheet title={TYPE_LABELS[type] ?? type}>
            <table className="w-full border-collapse text-left text-sm">
                <thead>
                    <tr className="border-b border-rule text-xs font-medium uppercase tracking-wide text-ink-700">
                        <th scope="col" className="py-2 pr-3 font-medium">
                            Code
                        </th>
                        <th scope="col" className="py-2 pr-3 font-medium">
                            Name
                        </th>
                        <th scope="col" className="py-2 pr-3 font-medium">
                            Type
                        </th>
                        <th scope="col" className="py-2 pr-3 text-right font-medium">
                            Balance
                        </th>
                        <th scope="col" className="py-2 pl-3 font-medium">
                            <span className="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {accounts.map((account) => (
                        <tr
                            key={account.id}
                            tabIndex={0}
                            role="button"
                            onClick={() => router.visit(route('accounts.show', account.id))}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter' || event.key === ' ') {
                                    event.preventDefault();
                                    router.visit(route('accounts.show', account.id));
                                }
                            }}
                            className={
                                `cursor-pointer border-b border-rule text-ink transition duration-[120ms] ease-out motion-reduce:transition-none hover:bg-paper focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-spark last:border-b-0 ` +
                                (account.is_archived ? 'opacity-50' : '')
                            }
                        >
                            <td className="py-3 pr-3 align-top font-mono">{account.code}</td>
                            <td className="py-3 pr-3 align-top">
                                <span className="inline-flex items-center gap-1.5">
                                    {account.name}
                                    {account.is_system && <LockIcon />}
                                    {account.is_archived && <ArchivedTag />}
                                </span>
                            </td>
                            <td className="py-3 pr-3 align-top text-ink-700">
                                {account.subtype_label}
                            </td>
                            <td className="py-3 pr-3 align-top">
                                <MoneyText minor={account.balance} currency={currency} size="sm" />
                            </td>
                            <td className="py-3 pl-3 align-top text-right">
                                <div className="flex justify-end gap-1">
                                    <Button
                                        type="button"
                                        variant="quiet"
                                        size="sm"
                                        onClick={(event) => {
                                            event.stopPropagation();
                                            onEdit(account);
                                        }}
                                    >
                                        Edit
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="quiet"
                                        size="sm"
                                        onClick={(event) => {
                                            event.stopPropagation();
                                            onToggleArchive(account);
                                        }}
                                    >
                                        {account.is_archived ? 'Unarchive' : 'Archive'}
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </Sheet>
    );
}

export default function Index({ accounts }: IndexPageProps) {
    const { currentCompany } = usePage<PageProps>().props;
    const currency = currentCompany?.base_currency ?? 'USD';
    const [showArchived, setShowArchived] = useState(false);
    const [editingAccount, setEditingAccount] = useState<AccountRow | null>(null);
    const [creating, setCreating] = useState(false);

    const visibleAccounts = useMemo(
        () => (showArchived ? accounts : accounts.filter((a) => !a.is_archived)),
        [accounts, showArchived],
    );

    const grouped = useMemo(() => {
        const byType = new Map<string, AccountRow[]>();
        for (const account of visibleAccounts) {
            const list = byType.get(account.type) ?? [];
            list.push(account);
            byType.set(account.type, list);
        }
        return byType;
    }, [visibleAccounts]);

    function toggleArchive(account: AccountRow) {
        const routeName = account.is_archived ? 'accounts.unarchive' : 'accounts.archive';
        router.post(route(routeName, account.id), {}, { preserveScroll: true });
    }

    return (
        <AppShell title="Chart of accounts">
            <Head title="Chart of accounts" />

            <div className="mb-4 flex items-center justify-between">
                <label className="flex items-center gap-2 text-sm text-ink-700">
                    <input
                        type="checkbox"
                        checked={showArchived}
                        onChange={(event) => setShowArchived(event.target.checked)}
                        className="h-4 w-4 rounded border-rule text-spark focus:ring-spark"
                    />
                    Show archived
                </label>
                <Button type="button" onClick={() => setCreating(true)}>
                    New account
                </Button>
            </div>

            <div className="flex flex-col gap-6">
                {TYPE_ORDER.map((type) => (
                    <AccountTypeSheet
                        key={type}
                        type={type}
                        accounts={grouped.get(type) ?? []}
                        currency={currency}
                        onEdit={setEditingAccount}
                        onToggleArchive={toggleArchive}
                    />
                ))}
            </div>

            {creating && (
                <AccountFormDialog account={null} onClose={() => setCreating(false)} />
            )}
            {editingAccount && (
                <AccountFormDialog
                    account={editingAccount}
                    onClose={() => setEditingAccount(null)}
                />
            )}
        </AppShell>
    );
}
