import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

interface AccountHeader {
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

interface LedgerRow {
    id: number;
    entry_date: string;
    memo: string | null;
    debit: number;
    credit: number;
    running_balance: number;
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

interface ShowPageProps extends PageProps {
    account: AccountHeader;
    ledger: Paginated<LedgerRow>;
}

function formatDisplayDate(isoDate: string): string {
    const [year, month, day] = isoDate.split('-').map(Number);
    return new Date(year, month - 1, day).toLocaleDateString('en-PK', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

function Pagination({ ledger }: { ledger: Paginated<LedgerRow> }) {
    if (ledger.last_page <= 1) {
        return null;
    }

    return (
        <nav
            aria-label="Ledger pages"
            className="mt-4 flex items-center justify-center gap-1"
        >
            {ledger.links.map((link, index) => {
                const baseClasses =
                    'flex h-8 min-w-8 items-center justify-center rounded-md px-2 text-sm transition duration-[120ms] ease-out motion-reduce:transition-none ';

                // Laravel's own paginator supplies these labels server-side —
                // never user input — so rendering the HTML entity is safe.
                const label = { __html: link.label };

                // A disabled link (first/last page's Prev/Next) has no url.
                // Render it as an inert <span>, not a focusable <Link> — so a
                // keyboard user can't Tab to it and Enter-activate a visit to
                // "#". pointer-events:none alone only blocks the mouse.
                if (link.url === null) {
                    return (
                        <span
                            key={`${link.label}-${index}`}
                            aria-disabled="true"
                            className={baseClasses + 'text-ink-700/40'}
                            dangerouslySetInnerHTML={label}
                        />
                    );
                }

                return (
                    <Link
                        key={`${link.label}-${index}`}
                        href={link.url}
                        preserveScroll
                        aria-current={link.active ? 'page' : undefined}
                        className={
                            baseClasses +
                            (link.active
                                ? 'bg-ink text-white'
                                : 'text-ink-700 hover:bg-paper')
                        }
                        dangerouslySetInnerHTML={label}
                    />
                );
            })}
        </nav>
    );
}

export default function Show({ account, ledger }: ShowPageProps) {
    const { currentCompany } = usePage<PageProps>().props;
    const currency = currentCompany?.base_currency ?? 'USD';

    return (
        <AppShell title={`${account.code} — ${account.name}`}>
            <Head title={`${account.code} — ${account.name}`} />

            <Link
                href={route('accounts.index')}
                className="mb-4 inline-block text-sm font-medium text-lagoon hover:underline"
            >
                &larr; Back to chart of accounts
            </Link>

            <Sheet className="mb-6">
                <div className="flex items-start justify-between">
                    <div>
                        <p className="font-mono text-sm text-ink-700">{account.code}</p>
                        <h2 className="font-display text-2xl font-semibold text-ink">
                            {account.name}
                        </h2>
                        <p className="mt-1 text-sm text-ink-700">{account.subtype_label}</p>
                        {account.description && (
                            <p className="mt-2 max-w-prose text-sm text-ink-700">
                                {account.description}
                            </p>
                        )}
                    </div>
                    <div className="text-right">
                        <p className="text-xs font-medium uppercase tracking-wide text-ink-700">
                            Current balance
                        </p>
                        <MoneyText minor={account.balance} currency={currency} size="lg" />
                    </div>
                </div>
            </Sheet>

            <Sheet title="Ledger">
                {ledger.data.length === 0 ? (
                    <p className="py-8 text-center text-sm text-ink-700">
                        No transactions posted to this account yet.
                    </p>
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
                                        Debit
                                    </th>
                                    <th scope="col" className="py-2 pr-3 text-right font-medium">
                                        Credit
                                    </th>
                                    <th scope="col" className="py-2 pl-3 text-right font-medium">
                                        Running balance
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {ledger.data.map((line) => (
                                    <tr
                                        key={line.id}
                                        className="border-b border-rule text-ink last:border-b-0"
                                    >
                                        <td className="py-3 pr-3 align-top">
                                            {formatDisplayDate(line.entry_date)}
                                        </td>
                                        <td className="py-3 pr-3 align-top text-ink-700">
                                            {line.memo ?? ''}
                                        </td>
                                        <td className="py-3 pr-3 align-top">
                                            <MoneyText
                                                minor={line.debit}
                                                currency={currency}
                                                size="sm"
                                            />
                                        </td>
                                        <td className="py-3 pr-3 align-top">
                                            <MoneyText
                                                minor={line.credit}
                                                currency={currency}
                                                size="sm"
                                            />
                                        </td>
                                        <td className="py-3 pl-3 align-top">
                                            <MoneyText
                                                minor={line.running_balance}
                                                currency={currency}
                                                size="sm"
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>

                        <Pagination ledger={ledger} />
                    </>
                )}
            </Sheet>
        </AppShell>
    );
}
