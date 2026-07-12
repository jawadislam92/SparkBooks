import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface AccountQuickReportLine {
    id: number;
    account_id: number;
    account_code: string;
    account_name: string;
    entry_id: number;
    entry_number: number | null;
    entry_number_display: string | null;
    reverses_entry_number: number | null;
    reverses_entry_number_display: string | null;
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

interface AccountQuickReportData {
    from: string;
    to: string;
    account_id: number;
    code: string;
    name: string;
    type: string;
    is_leaf: boolean;
    opening_balance: number;
    lines: AccountQuickReportLine[];
    closing_balance: number;
}

interface AccountOption {
    id: number;
    code: string;
    name: string;
}

interface AccountQuickReportPageProps extends PageProps {
    report: AccountQuickReportData | null;
    accounts: AccountOption[];
    from: string;
    to: string;
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
 * Account QuickReport (Phase 7 Task 5): one account's full transaction list
 * over a chosen [from, to] period -- the drill-down TARGET every figure in
 * P&L / Balance Sheet / Trial Balance links into via
 * ?account_id=&from=&to=. An accountant screen (PROJECT_MAP §9 rule 8),
 * debit/credit columns shown as-is, same convention as General Ledger. When
 * the selected account is a PARENT (sub-account header), the line list is
 * every descendant leaf's lines rolled up into one combined, date-ordered
 * list -- the `account_code`/`account_name` column on each line shows which
 * sub-account it actually came from (see AccountQuickReport.php's class
 * docblock for the rollup rule; that column is redundant, and hidden, for a
 * leaf account since every line already belongs to it).
 */
export default function AccountQuickReport({ report, accounts, from: initialFrom, to: initialTo, canExport }: AccountQuickReportPageProps) {
    const { currentCompany } = usePage<PageProps>().props;
    const currency = currentCompany?.base_currency ?? 'USD';
    const [account, setAccount] = useState(report ? String(report.account_id) : '');
    const [from, setFrom] = useState(report?.from ?? initialFrom);
    const [to, setTo] = useState(report?.to ?? initialTo);

    function reload(next: { account?: string; from?: string; to?: string }) {
        const nextAccount = next.account ?? account;
        const nextFrom = next.from ?? from;
        const nextTo = next.to ?? to;

        setAccount(nextAccount);
        setFrom(nextFrom);
        setTo(nextTo);

        if (!nextFrom || !nextTo) {
            return;
        }

        const query: Record<string, string> = { from: nextFrom, to: nextTo };
        if (nextAccount) {
            query.account_id = nextAccount;
        }

        router.get(route('reports.account-quickreport'), query, { preserveState: true, preserveScroll: true, replace: true });
    }

    const exportQuery = new URLSearchParams({ from, to });
    if (account) {
        exportQuery.set('account_id', account);
    }
    const exportHref = `${route('reports.account-quickreport.export')}?${exportQuery.toString()}`;

    return (
        <AppShell title="Account QuickReport">
            <Head title="Account QuickReport" />

            <Sheet
                title="Account QuickReport"
                actions={
                    <div className="flex flex-wrap items-center gap-2 print:hidden">
                        <label htmlFor="qr-account" className="flex items-center gap-2 text-sm text-ink-700">
                            Account
                            <select
                                id="qr-account"
                                value={account}
                                onChange={(event) => reload({ account: event.target.value })}
                                className={INPUT_CLASSES}
                            >
                                <option value="">Select an account…</option>
                                {accounts.map((option) => (
                                    <option key={option.id} value={option.id}>
                                        {option.code} — {option.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label htmlFor="qr-from" className="flex items-center gap-2 text-sm text-ink-700">
                            From
                            <input
                                id="qr-from"
                                type="date"
                                value={from}
                                onChange={(event) => reload({ from: event.target.value })}
                                className={INPUT_CLASSES}
                            />
                        </label>
                        <label htmlFor="qr-to" className="flex items-center gap-2 text-sm text-ink-700">
                            To
                            <input
                                id="qr-to"
                                type="date"
                                value={to}
                                onChange={(event) => reload({ to: event.target.value })}
                                className={INPUT_CLASSES}
                            />
                        </label>
                        {report && (
                            <button
                                type="button"
                                onClick={() => window.print()}
                                className="inline-flex h-8 items-center justify-center gap-2 rounded-lg px-3 font-sans text-xs font-semibold text-ink-700 transition duration-[120ms] ease-out hover:bg-paper focus:outline-none focus-visible:ring-2 focus-visible:ring-spark focus-visible:ring-offset-2 focus-visible:ring-offset-paper-raised motion-reduce:transition-none"
                            >
                                Print
                            </button>
                        )}
                        {report && canExport && (
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
                {!report ? (
                    <p className="text-sm text-ink-700">Select an account above to see its transaction detail.</p>
                ) : (
                    <div className="flex flex-col gap-4">
                        <h3 className="font-display text-sm font-semibold text-ink">
                            <span className="font-mono text-xs text-ink-700">{report.code}</span> — {report.name}
                        </h3>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-max border-collapse text-left text-sm">
                                <thead>
                                    <tr className="border-b border-rule text-xs font-medium uppercase tracking-wide text-ink-700">
                                        {!report.is_leaf && <th scope="col" className="py-2 pr-3 font-medium">Sub-account</th>}
                                        <th scope="col" className="py-2 pr-3 font-medium">Date</th>
                                        <th scope="col" className="py-2 pr-3 font-medium">Entry</th>
                                        <th scope="col" className="py-2 pr-3 font-medium">Source</th>
                                        <th scope="col" className="py-2 pr-3 font-medium">Contact</th>
                                        <th scope="col" className="py-2 pl-3 text-right font-medium">Debit</th>
                                        <th scope="col" className="py-2 pl-3 text-right font-medium">Credit</th>
                                        <th scope="col" className="py-2 pl-3 text-right font-medium">Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr className="border-b border-rule text-ink-700">
                                        <td className="py-2 pr-3 italic" colSpan={report.is_leaf ? 6 : 7}>
                                            Opening balance
                                        </td>
                                        <td className="py-2 pl-3">
                                            <MoneyText minor={report.opening_balance} currency={currency} size="sm" />
                                        </td>
                                    </tr>
                                    {report.lines.map((line) => (
                                        <tr key={line.id} className="border-b border-rule text-ink last:border-b-0">
                                            {!report.is_leaf && (
                                                <td className="py-2 pr-3 align-top text-ink-700">
                                                    <span className="font-mono text-xs">{line.account_code}</span> {line.account_name}
                                                </td>
                                            )}
                                            <td className="py-2 pr-3 align-top">{formatDisplayDate(line.entry_date)}</td>
                                            <td className="py-2 pr-3 align-top text-ink-700">
                                                {line.entry_number_display ?? `Entry #${line.entry_id}`}
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
                                            <td className="py-2 pl-3 align-top">
                                                <MoneyText minor={line.running_balance} currency={currency} size="sm" />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot>
                                    <tr className="border-t-2 border-rule font-semibold text-ink">
                                        <td className="py-2 pr-3" colSpan={report.is_leaf ? 6 : 7}>Closing balance</td>
                                        <td className="py-2 pl-3">
                                            <MoneyText minor={report.closing_balance} currency={currency} size="sm" />
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                )}
            </Sheet>
        </AppShell>
    );
}
