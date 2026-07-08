import Button from '@/Components/ui/Button';
import Field from '@/Components/ui/Field';
import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Dialog,
    DialogBackdrop,
    DialogPanel,
    DialogTitle,
} from '@headlessui/react';
import { Fragment, FormEventHandler, useState } from 'react';

interface JournalLineData {
    id: number;
    debit: number;
    credit: number;
    memo: string | null;
    account: {
        id: number;
        code: string;
        name: string;
    };
}

interface JournalEntryData {
    id: number;
    lines: JournalLineData[];
}

interface ManualJournalData {
    id: number;
    entry_date: string;
    memo: string;
    status: 'posted' | 'reversed' | string;
    created_by: string | null;
    journal_entry: JournalEntryData | null;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Paginated<T> {
    data: T[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    total: number;
}

interface IndexPageProps extends PageProps {
    journals: Paginated<ManualJournalData>;
}

function todayIsoDate(): string {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function formatDisplayDate(isoDate: string): string {
    const [year, month, day] = isoDate.split('-').map(Number);
    return new Date(year, month - 1, day).toLocaleDateString('en-PK', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

function entryTotal(journal: ManualJournalData): number {
    if (!journal.journal_entry) {
        return 0;
    }
    return journal.journal_entry.lines.reduce((sum, line) => sum + line.debit, 0);
}

const INPUT_CLASSES =
    'h-10 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

function StatusChip({ status }: { status: string }) {
    if (status === 'reversed') {
        return (
            <span className="inline-block rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-ink">
                Reversed
            </span>
        );
    }

    return (
        <span className="inline-block rounded-full bg-spark-50 px-2.5 py-0.5 text-xs font-medium text-ink">
            Posted
        </span>
    );
}

interface ReverseFormData {
    date: string;
    memo: string;
}

function ReverseDialog({
    journal,
    onClose,
}: {
    journal: ManualJournalData;
    onClose: () => void;
}) {
    const form = useForm<ReverseFormData>({
        date: todayIsoDate(),
        memo: '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.post(route('journal-entries.reverse', journal.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open onClose={onClose} className="relative z-50">
            <DialogBackdrop className="fixed inset-0 bg-ink/30" />
            <div className="fixed inset-0 flex items-center justify-center p-4">
                <DialogPanel className="w-full max-w-sm rounded-sheet bg-paper-raised p-6 shadow-sheet">
                    <DialogTitle className="font-display text-lg font-semibold text-ink">
                        Reverse journal entry
                    </DialogTitle>
                    <p className="mt-1 text-sm text-ink-700">
                        Posts a mirror entry that cancels out &ldquo;{journal.memo}&rdquo;. The
                        original entry stays on the books, unmodified.
                    </p>

                    {errors.memo && !form.data.memo && (
                        <p role="alert" className="mt-3 rounded-md bg-clay-50 px-3 py-2 text-sm text-clay">
                            {errors.memo}
                        </p>
                    )}

                    <form onSubmit={submit} className="mt-4 flex flex-col gap-4">
                        <Field label="Reversal date" htmlFor="reverse-date" error={errors.date}>
                            <input
                                id="reverse-date"
                                type="date"
                                value={form.data.date}
                                onChange={(event) => form.setData('date', event.target.value)}
                                className={INPUT_CLASSES}
                            />
                        </Field>
                        <Field label="Memo" htmlFor="reverse-memo" error={errors.memo}>
                            <input
                                id="reverse-memo"
                                type="text"
                                value={form.data.memo}
                                onChange={(event) => form.setData('memo', event.target.value)}
                                placeholder="Why is this being reversed?"
                                className={INPUT_CLASSES}
                            />
                        </Field>
                        <div className="mt-2 flex justify-end gap-2">
                            <Button type="button" variant="quiet" onClick={onClose}>
                                Cancel
                            </Button>
                            <Button type="submit" variant="danger" disabled={form.processing}>
                                {form.processing ? 'Reversing…' : 'Reverse entry'}
                            </Button>
                        </div>
                    </form>
                </DialogPanel>
            </div>
        </Dialog>
    );
}

function EmptyState() {
    return (
        <div className="flex flex-col items-center gap-4 py-16 text-center">
            <p className="text-sm text-ink-700">
                No journal entries yet — post your first manual entry to get started.
            </p>
            <Button
                type="button"
                onClick={() => router.visit(route('journal-entries.new'))}
            >
                New journal entry
            </Button>
        </div>
    );
}

function Pagination({ journals }: { journals: Paginated<ManualJournalData> }) {
    if (journals.last_page <= 1) {
        return null;
    }

    return (
        <nav
            aria-label="Journal entries pages"
            className="mt-4 flex items-center justify-center gap-1"
        >
            {journals.links.map((link, index) => (
                <Link
                    key={`${link.label}-${index}`}
                    href={link.url ?? '#'}
                    preserveScroll
                    aria-current={link.active ? 'page' : undefined}
                    aria-disabled={link.url === null}
                    className={
                        'flex h-8 min-w-8 items-center justify-center rounded-md px-2 text-sm transition duration-[120ms] ease-out motion-reduce:transition-none ' +
                        (link.active
                            ? 'bg-ink text-white'
                            : link.url === null
                              ? 'pointer-events-none text-ink-700/40'
                              : 'text-ink-700 hover:bg-paper')
                    }
                    // Laravel's own paginator supplies these labels ("&laquo;
                    // Previous", page numbers, "Next &raquo;") server-side —
                    // never user input — so rendering the HTML entity is safe.
                    dangerouslySetInnerHTML={{ __html: link.label }}
                />
            ))}
        </nav>
    );
}

export default function Index({ journals }: IndexPageProps) {
    const { currentCompany } = usePage<PageProps>().props;
    const currency = currentCompany?.base_currency ?? 'USD';
    const [expandedId, setExpandedId] = useState<number | null>(null);
    const [reversingJournal, setReversingJournal] = useState<ManualJournalData | null>(
        null,
    );

    function toggleExpanded(id: number) {
        setExpandedId((current) => (current === id ? null : id));
    }

    return (
        <AppShell title="Journal entries">
            <Head title="Journal entries" />

            <Sheet
                title="Journal entries"
                actions={
                    <Button
                        type="button"
                        onClick={() => router.visit(route('journal-entries.new'))}
                    >
                        New journal entry
                    </Button>
                }
            >
                {journals.data.length === 0 ? (
                    <EmptyState />
                ) : (
                    <>
                        <table className="w-full border-collapse text-left text-sm">
                            <thead>
                                <tr className="border-b border-rule text-xs font-medium uppercase tracking-wide text-ink-700">
                                    <th scope="col" className="py-2 pr-3 font-medium">
                                        Date
                                    </th>
                                    <th scope="col" className="py-2 pr-3 font-medium">
                                        Memo
                                    </th>
                                    <th scope="col" className="py-2 pr-3 text-right font-medium">
                                        Amount
                                    </th>
                                    <th scope="col" className="py-2 pr-3 font-medium">
                                        Status
                                    </th>
                                    <th scope="col" className="py-2 pr-3 font-medium">
                                        Created by
                                    </th>
                                    <th scope="col" className="py-2 pl-3 font-medium">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {journals.data.map((journal) => {
                                    const isExpanded = expandedId === journal.id;
                                    const detailId = `journal-${journal.id}-detail`;

                                    return (
                                        <Fragment key={journal.id}>
                                            <tr
                                                tabIndex={0}
                                                role="button"
                                                aria-expanded={isExpanded}
                                                aria-controls={detailId}
                                                onClick={() => toggleExpanded(journal.id)}
                                                onKeyDown={(event) => {
                                                    if (event.key === 'Enter' || event.key === ' ') {
                                                        event.preventDefault();
                                                        toggleExpanded(journal.id);
                                                    }
                                                }}
                                                className="cursor-pointer border-b border-rule text-ink transition duration-[120ms] ease-out motion-reduce:transition-none hover:bg-paper focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-spark"
                                            >
                                                <td className="py-3 pr-3 align-top">
                                                    {formatDisplayDate(journal.entry_date)}
                                                </td>
                                                <td className="py-3 pr-3 align-top">{journal.memo}</td>
                                                <td className="py-3 pr-3 align-top">
                                                    <MoneyText
                                                        minor={entryTotal(journal)}
                                                        currency={currency}
                                                        size="sm"
                                                    />
                                                </td>
                                                <td className="py-3 pr-3 align-top">
                                                    <StatusChip status={journal.status} />
                                                </td>
                                                <td className="py-3 pr-3 align-top text-ink-700">
                                                    {journal.created_by ?? '—'}
                                                </td>
                                                <td className="py-3 pl-3 align-top text-right">
                                                    {journal.status === 'posted' && (
                                                        <Button
                                                            type="button"
                                                            variant="quiet"
                                                            size="sm"
                                                            onClick={(event) => {
                                                                event.stopPropagation();
                                                                setReversingJournal(journal);
                                                            }}
                                                        >
                                                            Reverse
                                                        </Button>
                                                    )}
                                                </td>
                                            </tr>
                                            {isExpanded && (
                                                <tr id={detailId} className="border-b border-rule bg-paper">
                                                    <td colSpan={6} className="px-3 py-3">
                                                        <table className="w-full border-collapse text-sm">
                                                            <thead>
                                                                <tr className="text-xs font-medium uppercase tracking-wide text-ink-700">
                                                                    <th scope="col" className="pb-1.5 pr-3 text-left font-medium">
                                                                        Account
                                                                    </th>
                                                                    <th scope="col" className="pb-1.5 pr-3 text-left font-medium">
                                                                        Line memo
                                                                    </th>
                                                                    <th scope="col" className="pb-1.5 pr-3 text-right font-medium">
                                                                        Debit
                                                                    </th>
                                                                    <th scope="col" className="pb-1.5 text-right font-medium">
                                                                        Credit
                                                                    </th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                {journal.journal_entry?.lines.map((line) => (
                                                                    <tr key={line.id}>
                                                                        <td className="py-1 pr-3 text-ink">
                                                                            {line.account.code} — {line.account.name}
                                                                        </td>
                                                                        <td className="py-1 pr-3 text-ink-700">
                                                                            {line.memo ?? ''}
                                                                        </td>
                                                                        <td className="py-1 pr-3">
                                                                            <MoneyText
                                                                                minor={line.debit}
                                                                                currency={currency}
                                                                                size="sm"
                                                                            />
                                                                        </td>
                                                                        <td className="py-1">
                                                                            <MoneyText
                                                                                minor={line.credit}
                                                                                currency={currency}
                                                                                size="sm"
                                                                            />
                                                                        </td>
                                                                    </tr>
                                                                ))}
                                                            </tbody>
                                                        </table>
                                                    </td>
                                                </tr>
                                            )}
                                        </Fragment>
                                    );
                                })}
                            </tbody>
                        </table>

                        <Pagination journals={journals} />
                    </>
                )}
            </Sheet>

            {reversingJournal && (
                <ReverseDialog
                    journal={reversingJournal}
                    onClose={() => setReversingJournal(null)}
                />
            )}
        </AppShell>
    );
}
