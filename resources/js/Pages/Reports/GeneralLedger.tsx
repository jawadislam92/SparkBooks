import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface GeneralLedgerLine {
    id: number;
    entry_id: number;
    entry_date: string;
    entry_role: 'primary' | 'reversal';
    source_type: string;
    source_id: number;
    source_label: string;
    memo: string | null;
    contact_id: number | null;
    contact_name: string | null;
    debit: number;
    credit: number;
    running_balance: number;
}

interface GeneralLedgerAccount {
    account_id: number;
    code: string;
    name: string;
    type: string;
    opening_balance: number;
    lines: GeneralLedgerLine[];
    closing_balance: number;
}

interface GeneralLedgerReportData {
    from: string;
    to: string;
    account_id: number | null;
    contact_id: number | null;
    accounts: GeneralLedgerAccount[];
}

interface AccountOption {
    id: number;
    code: string;
    name: string;
}

interface GeneralLedgerPageProps extends PageProps {
    report: GeneralLedgerReportData;
    accounts: AccountOption[];
    contactName: string | null;
    canExport: boolean;
}

const INPUT_CLASSES =
    'h-9 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

function formatDisplayDate(isoDate: string): string {
    const [year, month, day] = isoDate.split('-').map(Number);
    return new Date(year, month - 1, day).toLocaleDateString('en-PK', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

/**
 * When `showBalance` is false (a contact drill-down is active), the
 * opening/closing balance rows AND the running-balance column are hidden.
 * Those figures are the WHOLE account's balance netted through only this
 * contact's lines -- neither the contact's balance nor the account's real
 * balance (closing == cumulativeAsOf(to) only holds when no contact filter
 * is applied; see GeneralLedgerReport's class docblock). Showing them under
 * a contact filter would invite an accountant who clicked a customer name
 * to misread the closing figure as that customer's balance, so the filtered
 * view is deliberately reduced to an honest per-contact line list.
 */
function AccountSection({
    account,
    currency,
    showBalance,
}: {
    account: GeneralLedgerAccount;
    currency: string;
    showBalance: boolean;
}) {
    return (
        <section>
            <h3 className="pb-2 font-display text-sm font-semibold text-ink">
                <span className="font-mono text-xs text-ink-700">{account.code}</span> — {account.name}
            </h3>
            <div className="overflow-x-auto">
                <table className="w-full min-w-max border-collapse text-left text-sm">
                    <thead>
                        <tr className="border-b border-rule text-xs font-medium uppercase tracking-wide text-ink-700">
                            <th scope="col" className="py-2 pr-3 font-medium">Date</th>
                            <th scope="col" className="py-2 pr-3 font-medium">Entry</th>
                            <th scope="col" className="py-2 pr-3 font-medium">Source</th>
                            <th scope="col" className="py-2 pr-3 font-medium">Contact</th>
                            <th scope="col" className="py-2 pl-3 text-right font-medium">Debit</th>
                            <th scope="col" className="py-2 pl-3 text-right font-medium">Credit</th>
                            {showBalance && (
                                <th scope="col" className="py-2 pl-3 text-right font-medium">Balance</th>
                            )}
                        </tr>
                    </thead>
                    <tbody>
                        {showBalance && (
                            <tr className="border-b border-rule text-ink-700">
                                <td className="py-2 pr-3 italic" colSpan={6}>Opening balance</td>
                                <td className="py-2 pl-3">
                                    <MoneyText minor={account.opening_balance} currency={currency} size="sm" />
                                </td>
                            </tr>
                        )}
                        {account.lines.map((line) => (
                            <tr key={line.id} className="border-b border-rule text-ink last:border-b-0">
                                <td className="py-2 pr-3 align-top">{formatDisplayDate(line.entry_date)}</td>
                                <td className="py-2 pr-3 align-top text-ink-700">
                                    Entry #{line.entry_id}
                                    {line.entry_role === 'reversal' && (
                                        <span className="ml-1.5 rounded-full bg-paper px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-clay">
                                            Reversal
                                        </span>
                                    )}
                                    {line.memo && <div className="text-xs text-ink-700">{line.memo}</div>}
                                </td>
                                <td className="py-2 pr-3 align-top text-ink-700">{line.source_label}</td>
                                <td className="py-2 pr-3 align-top text-ink-700">{line.contact_name ?? '—'}</td>
                                <td className="py-2 pl-3 align-top">
                                    <MoneyText minor={line.debit} currency={currency} size="sm" />
                                </td>
                                <td className="py-2 pl-3 align-top">
                                    <MoneyText minor={line.credit} currency={currency} size="sm" />
                                </td>
                                {showBalance && (
                                    <td className="py-2 pl-3 align-top">
                                        <MoneyText minor={line.running_balance} currency={currency} size="sm" />
                                    </td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                    {showBalance && (
                        <tfoot>
                            <tr className="border-t-2 border-rule font-semibold text-ink">
                                <td className="py-2 pr-3" colSpan={6}>Closing balance</td>
                                <td className="py-2 pl-3">
                                    <MoneyText minor={account.closing_balance} currency={currency} size="sm" />
                                </td>
                            </tr>
                        </tfoot>
                    )}
                </table>
            </div>
        </section>
    );
}

/**
 * The General Ledger (spec §7.11 item 5): per-account line detail over a
 * chosen [from, to] period -- the Gate-B artifact and the drill-down TARGET
 * every other report (P&L, Balance Sheet, agings) links into via
 * ?account=&from=&to=&contact=. GL is an accountant screen (PROJECT_MAP §9
 * rule 8 does not restrict it) -- debit/credit columns are shown as-is,
 * unlike P&L/Balance Sheet/agings. The running/opening/closing balance
 * column is presented in the account's own natural side (see
 * GeneralLedgerReport's class docblock for the sign convention).
 */
export default function GeneralLedger({ report, accounts, contactName, canExport }: GeneralLedgerPageProps) {
    const { currentCompany } = usePage<PageProps>().props;
    const currency = currentCompany?.base_currency ?? 'USD';
    const [from, setFrom] = useState(report.from);
    const [to, setTo] = useState(report.to);
    const [account, setAccount] = useState(report.account_id !== null ? String(report.account_id) : '');
    const [contactId, setContactId] = useState(report.contact_id);

    function reload(next: { from?: string; to?: string; account?: string }, keepContact = true) {
        const nextFrom = next.from ?? from;
        const nextTo = next.to ?? to;
        const nextAccount = next.account ?? account;

        setFrom(nextFrom);
        setTo(nextTo);
        setAccount(nextAccount);
        if (!keepContact) {
            setContactId(null);
        }

        if (!nextFrom || !nextTo) {
            return;
        }

        const query: Record<string, string> = { from: nextFrom, to: nextTo };
        if (nextAccount) {
            query.account = nextAccount;
        }
        if (keepContact && contactId !== null) {
            query.contact = String(contactId);
        }

        router.get(route('reports.general-ledger'), query, { preserveState: true, preserveScroll: true, replace: true });
    }

    const exportQuery = new URLSearchParams({ from, to });
    if (account) {
        exportQuery.set('account', account);
    }
    if (contactId !== null) {
        exportQuery.set('contact', String(contactId));
    }
    const exportHref = `${route('reports.general-ledger.export')}?${exportQuery.toString()}`;

    const isEmpty = report.accounts.length === 0;

    return (
        <AppShell title="General ledger">
            <Head title="General ledger" />

            <Sheet
                title="General ledger"
                actions={
                    <div className="flex flex-wrap items-center gap-2 print:hidden">
                        <label htmlFor="gl-account" className="flex items-center gap-2 text-sm text-ink-700">
                            Account
                            <select
                                id="gl-account"
                                value={account}
                                onChange={(event) => reload({ account: event.target.value })}
                                className={INPUT_CLASSES}
                            >
                                <option value="">All accounts</option>
                                {accounts.map((option) => (
                                    <option key={option.id} value={option.id}>
                                        {option.code} — {option.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label htmlFor="gl-from" className="flex items-center gap-2 text-sm text-ink-700">
                            From
                            <input
                                id="gl-from"
                                type="date"
                                value={from}
                                onChange={(event) => reload({ from: event.target.value })}
                                className={INPUT_CLASSES}
                            />
                        </label>
                        <label htmlFor="gl-to" className="flex items-center gap-2 text-sm text-ink-700">
                            To
                            <input
                                id="gl-to"
                                type="date"
                                value={to}
                                onChange={(event) => reload({ to: event.target.value })}
                                className={INPUT_CLASSES}
                            />
                        </label>
                        <button
                            type="button"
                            onClick={() => window.print()}
                            className="inline-flex h-8 items-center justify-center gap-2 rounded-lg px-3 font-sans text-xs font-semibold text-ink-700 transition duration-[120ms] ease-out hover:bg-paper focus:outline-none focus-visible:ring-2 focus-visible:ring-spark focus-visible:ring-offset-2 focus-visible:ring-offset-paper-raised motion-reduce:transition-none"
                        >
                            Print
                        </button>
                        {canExport && (
                            <a
                                href={exportHref}
                                className="inline-flex h-8 items-center justify-center gap-2 rounded-lg px-3 font-sans text-xs font-semibold text-ink-700 transition duration-[120ms] ease-out hover:bg-paper focus:outline-none focus-visible:ring-2 focus-visible:ring-spark focus-visible:ring-offset-2 focus-visible:ring-offset-paper-raised motion-reduce:transition-none"
                            >
                                Export CSV
                            </a>
                        )}
                    </div>
                }
            >
                {contactId !== null && (
                    <div className="mb-4 flex items-center justify-between gap-4 rounded-md bg-paper px-3 py-2 text-sm text-ink print:hidden">
                        <span>
                            Showing only {contactName ?? `contact #${contactId}`}&rsquo;s lines. Opening,
                            running, and closing balances are hidden because they reflect the whole account,
                            not this contact.
                        </span>
                        <button
                            type="button"
                            onClick={() => reload({}, false)}
                            className="shrink-0 font-medium text-lagoon underline decoration-dotted underline-offset-4 hover:no-underline"
                        >
                            Clear filter
                        </button>
                    </div>
                )}

                {isEmpty ? (
                    <p className="text-sm text-ink-700">No activity for this period.</p>
                ) : (
                    <div className="flex flex-col gap-8">
                        {report.accounts.map((accountRow) => (
                            <AccountSection
                                key={accountRow.account_id}
                                account={accountRow}
                                currency={currency}
                                showBalance={contactId === null}
                            />
                        ))}
                    </div>
                )}
            </Sheet>
        </AppShell>
    );
}
