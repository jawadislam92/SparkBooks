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
    /** Phase 7 Task 8 (cf_class fix): the Statement of Cash Flows classification — null only for a legacy row that predates the fix. */
    cf_class: string | null;
    balance: number;
    /** Phase 7 Task 1 (sub-accounts): hierarchy fields. `parent_id` is null for a top-level account. */
    parent_id: number | null;
    is_postable: boolean;
    depth: number;
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

/**
 * Phase 7 Task 8 (cf_class fix): the 5 Statement of Cash Flows
 * classifications an accountant can reclassify a NON-cash/bank account into
 * on the edit form. A Cash/Bank subtype account never shows this dropdown —
 * see AccountFormDialog's own `isCashOrBank` branch — because
 * Account::booted()'s `saving` hook always forces it back to Cash anyway.
 */
const CF_CLASS_CATALOG: { value: string; label: string }[] = [
    { value: 'operating', label: 'Operating' },
    { value: 'investing', label: 'Investing' },
    { value: 'financing', label: 'Financing' },
    { value: 'cash', label: 'Cash' },
    { value: 'excluded', label: 'Excluded' },
];

const TYPE_LABELS: Record<string, string> = {
    asset: 'Assets',
    liability: 'Liabilities',
    equity: 'Equity',
    income: 'Income',
    expense: 'Expenses',
};

const TYPE_ORDER = ['asset', 'liability', 'equity', 'income', 'expense'];

/** Indent per hierarchy level — a sub-account's row nudges right of its parent's. */
const INDENT_REM_PER_DEPTH = 1.25;

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

/** Marks a row as a header (parent/rollup) account — distinct from a plain postable leaf. */
function HeaderTag() {
    return (
        <span
            title="Header account — its balance is the total of its sub-accounts"
            className="ml-2 inline-flex items-center rounded-full bg-spark-50 px-2 py-0.5 text-xs font-medium text-spark-600"
        >
            Header
        </span>
    );
}

function ChevronIcon({ expanded }: { expanded: boolean }) {
    return (
        <svg
            aria-hidden="true"
            viewBox="0 0 20 20"
            fill="currentColor"
            className={`h-3.5 w-3.5 shrink-0 transition-transform duration-[120ms] ease-out motion-reduce:transition-none ${expanded ? 'rotate-90' : ''}`}
        >
            <path
                fillRule="evenodd"
                d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z"
                clipRule="evenodd"
            />
        </svg>
    );
}

interface AccountFormData {
    code: string;
    name: string;
    subtype: string;
    description: string;
    cf_class: string;
}

function AccountFormDialog({
    account,
    onClose,
}: {
    /** Present => editing; absent => creating a new top-level account. */
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
        cf_class: account?.cf_class ?? '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    // Phase 7 Task 8 (cf_class fix): a Cash/Bank subtype account's
    // classification can never leave Cash — Account::booted()'s `saving`
    // hook forces it server-side regardless, so the control is hidden here
    // purely to avoid confusing the user with a choice that can't stick.
    const isCashOrBank = form.data.subtype === 'cash' || form.data.subtype === 'bank';

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

                        {isEditing && (
                            <Field
                                label="Cash flow classification"
                                htmlFor="account-cf-class"
                                error={errors.cf_class}
                            >
                                {isCashOrBank ? (
                                    <p className="flex h-10 items-center rounded-md border border-rule bg-paper px-3 text-sm text-ink-700">
                                        Cash — every cash/bank account is always part of the cash pool.
                                    </p>
                                ) : (
                                    <select
                                        id="account-cf-class"
                                        value={form.data.cf_class}
                                        onChange={(event) =>
                                            form.setData('cf_class', event.target.value)
                                        }
                                        className={INPUT_CLASSES}
                                    >
                                        <option value="" disabled>
                                            Select a classification…
                                        </option>
                                        {CF_CLASS_CATALOG.map((c) => (
                                            <option key={c.value} value={c.value}>
                                                {c.label}
                                            </option>
                                        ))}
                                    </select>
                                )}
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

interface SubAccountFormData {
    code: string;
    name: string;
    subtype: string;
    description: string;
}

/** "Add sub-account": creates a leaf under `parent`, restricted to `parent`'s own root type. */
function SubAccountFormDialog({
    parent,
    onClose,
}: {
    parent: AccountRow;
    onClose: () => void;
}) {
    const eligibleSubtypes = SUBTYPE_CATALOG.filter((s) => s.type === parent.type);

    const form = useForm<SubAccountFormData>({
        code: '',
        name: '',
        subtype: eligibleSubtypes[0]?.value ?? '',
        description: '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('accounts.children.store', parent.id), {
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
                        Add sub-account under {parent.name}
                    </DialogTitle>

                    <form onSubmit={submit} className="mt-4 flex flex-col gap-4">
                        <Field label="Code" htmlFor="sub-account-code" error={errors.code}>
                            <input
                                id="sub-account-code"
                                type="text"
                                inputMode="numeric"
                                value={form.data.code}
                                onChange={(event) => form.setData('code', event.target.value)}
                                placeholder="1234"
                                maxLength={4}
                                className={`${INPUT_CLASSES} font-mono`}
                            />
                        </Field>

                        <Field label="Name" htmlFor="sub-account-name" error={errors.name}>
                            <input
                                id="sub-account-name"
                                type="text"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                className={INPUT_CLASSES}
                            />
                        </Field>

                        <Field label="Type" htmlFor="sub-account-subtype" error={errors.subtype}>
                            <select
                                id="sub-account-subtype"
                                value={form.data.subtype}
                                onChange={(event) => form.setData('subtype', event.target.value)}
                                className={INPUT_CLASSES}
                            >
                                {eligibleSubtypes.map((subtype) => (
                                    <option key={subtype.value} value={subtype.value}>
                                        {subtype.label}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field
                            label="Description"
                            htmlFor="sub-account-description"
                            error={errors.description}
                        >
                            <textarea
                                id="sub-account-description"
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData('description', event.target.value)
                                }
                                rows={2}
                                className={`${INPUT_CLASSES} h-auto resize-none py-2`}
                            />
                        </Field>

                        {parent.is_postable ? (
                            <p className="text-xs text-ink-700">
                                {parent.name} will become a header account once this is added
                                {parent.balance !== 0
                                    ? ` — its existing activity moves to an auto-created "${parent.name} — Other" sub-account, so nothing about its balance changes.`
                                    : '.'}
                            </p>
                        ) : (
                            <p className="text-xs text-ink-700">
                                {parent.name} is already a header account — this adds another
                                sub-account alongside its existing ones.
                            </p>
                        )}

                        <div className="mt-2 flex justify-end gap-2">
                            <Button type="button" variant="quiet" onClick={onClose}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing ? 'Adding…' : 'Add sub-account'}
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
    hasChildren,
    collapsedIds,
    onToggleCollapse,
    onEdit,
    onToggleArchive,
    onAddSubAccount,
}: {
    type: string;
    /** Every account of this type, depth-first ordered (parent immediately before its descendants). */
    accounts: AccountRow[];
    currency: string;
    hasChildren: (accountId: number) => boolean;
    collapsedIds: Set<number>;
    onToggleCollapse: (accountId: number) => void;
    onEdit: (account: AccountRow) => void;
    onToggleArchive: (account: AccountRow) => void;
    onAddSubAccount: (account: AccountRow) => void;
}) {
    if (accounts.length === 0) {
        return null;
    }

    const byId = new Map(accounts.map((account) => [account.id, account]));

    /** Hidden when any ancestor is collapsed — walks the parent_id chain within this type's own list. */
    function isVisible(account: AccountRow): boolean {
        let parentId = account.parent_id;
        while (parentId !== null) {
            if (collapsedIds.has(parentId)) {
                return false;
            }
            parentId = byId.get(parentId)?.parent_id ?? null;
        }
        return true;
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
                    {accounts.filter(isVisible).map((account) => {
                        const expandable = hasChildren(account.id);
                        const expanded = !collapsedIds.has(account.id);

                        return (
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
                                    (account.is_archived ? 'opacity-50 ' : '') +
                                    (!account.is_postable ? 'bg-paper/60' : '')
                                }
                            >
                                <td className="py-3 pr-3 align-top font-mono">{account.code}</td>
                                <td className="py-3 pr-3 align-top">
                                    <div
                                        className="flex items-center gap-1"
                                        style={{
                                            paddingLeft: `${account.depth * INDENT_REM_PER_DEPTH}rem`,
                                        }}
                                    >
                                        {expandable ? (
                                            <button
                                                type="button"
                                                aria-label={expanded ? 'Collapse' : 'Expand'}
                                                onClick={(event) => {
                                                    event.stopPropagation();
                                                    onToggleCollapse(account.id);
                                                }}
                                                className="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded text-ink-700 hover:bg-paper"
                                            >
                                                <ChevronIcon expanded={expanded} />
                                            </button>
                                        ) : (
                                            <span className="inline-block h-5 w-5 shrink-0" />
                                        )}
                                        <span
                                            className={
                                                'inline-flex flex-wrap items-center gap-1.5 ' +
                                                (!account.is_postable ? 'font-semibold' : '')
                                            }
                                        >
                                            {account.name}
                                            {account.is_system && <LockIcon />}
                                            {!account.is_postable && <HeaderTag />}
                                            {account.is_archived && <ArchivedTag />}
                                        </span>
                                    </div>
                                </td>
                                <td className="py-3 pr-3 align-top text-ink-700">
                                    {account.subtype_label}
                                </td>
                                <td className="py-3 pr-3 align-top">
                                    <MoneyText minor={account.balance} currency={currency} size="sm" />
                                </td>
                                <td className="py-3 pl-3 align-top text-right">
                                    <div className="flex justify-end gap-1">
                                        {/* System accounts (Retained Earnings,
                                            AR/AP control, etc.) are referenced
                                            by code in posting rules and reports
                                            — they can never have sub-accounts,
                                            so the action is hidden (the server
                                            rejects it too, defence in depth). */}
                                        {!account.is_system && (
                                            <Button
                                                type="button"
                                                variant="quiet"
                                                size="sm"
                                                onClick={(event) => {
                                                    event.stopPropagation();
                                                    onAddSubAccount(account);
                                                }}
                                            >
                                                Add sub-account
                                            </Button>
                                        )}
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
                        );
                    })}
                </tbody>
            </table>
        </Sheet>
    );
}

export default function Index({ accounts }: IndexPageProps) {
    const { currentCompany, errors: pageErrors } = usePage<PageProps>().props;
    const errors = (pageErrors ?? {}) as Record<string, string | undefined>;
    const currency = currentCompany?.base_currency ?? 'USD';
    const [showArchived, setShowArchived] = useState(false);
    const [editingAccount, setEditingAccount] = useState<AccountRow | null>(null);
    const [addingChildTo, setAddingChildTo] = useState<AccountRow | null>(null);
    const [creating, setCreating] = useState(false);
    const [collapsedIds, setCollapsedIds] = useState<Set<number>>(new Set());

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

    /** Every account id that has at least one (currently visible) child — drives whether a row gets an expand/collapse chevron. */
    const parentIdsWithVisibleChildren = useMemo(() => {
        const set = new Set<number>();
        for (const account of visibleAccounts) {
            if (account.parent_id !== null) {
                set.add(account.parent_id);
            }
        }
        return set;
    }, [visibleAccounts]);

    function toggleCollapse(accountId: number) {
        setCollapsedIds((previous) => {
            const next = new Set(previous);
            if (next.has(accountId)) {
                next.delete(accountId);
            } else {
                next.add(accountId);
            }
            return next;
        });
    }

    function toggleArchive(account: AccountRow) {
        const routeName = account.is_archived ? 'accounts.unarchive' : 'accounts.archive';
        router.post(route(routeName, account.id), {}, { preserveScroll: true });
    }

    return (
        <AppShell title="Chart of accounts">
            <Head title="Chart of accounts" />

            {errors.is_archived && (
                <div role="alert" className="mb-4 rounded-md bg-clay-50 px-4 py-3 text-sm text-clay">
                    {errors.is_archived}
                </div>
            )}

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
                        hasChildren={(accountId) => parentIdsWithVisibleChildren.has(accountId)}
                        collapsedIds={collapsedIds}
                        onToggleCollapse={toggleCollapse}
                        onEdit={setEditingAccount}
                        onToggleArchive={toggleArchive}
                        onAddSubAccount={setAddingChildTo}
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
            {addingChildTo && (
                <SubAccountFormDialog
                    parent={addingChildTo}
                    onClose={() => setAddingChildTo(null)}
                />
            )}
        </AppShell>
    );
}
