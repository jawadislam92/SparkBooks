import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface TransactionDetailLine {
    id: number;
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

interface TransactionDetailAccount {
    account_id: number;
    code: string;
    name: string;
    type: string;
    lines: TransactionDetailLine[];
    subtotal_debit: number;
    subtotal_credit: number;
    net: number;
}

interface TransactionDetailReportData {
    from: string;
    to: string;
    accounts: TransactionDetailAccount[];
    grand_total_debit: number;
    grand_total_credit: number;
}

interface TransactionDetailPageProps extends PageProps {
    report: TransactionDetailReportData;
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
 * One account's section: every line posted in the period, with a running
 * balance that starts at ZERO at the top (this is a period-activity report,
 * not an open-to-close ledger view -- see AccountQuickReport/General Ledger
 * for that), and a Subtotal row that ties to LedgerBalances::movement (see
 * TransactionDetailReport's class docblock).
 */
function AccountSection({ account, currency }: { account: TransactionDetailAccount; currency: string }) {
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
                            <th scope="col" className="py-2 pl-3 text-right font-medium">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        {account.lines.map((line) => (
                            <tr key={line.id} className="border-b border-rule text-ink last:border-b-0">
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
                            <td className="py-2 pr-3" colSpan={4}>Subtotal</td>
                            <td className="py-2 pl-3">
                                <MoneyText minor={account.subtotal_debit} currency={currency} size="sm" />
                            </td>
                            <td className="py-2 pl-3">
                                <MoneyText minor={account.subtotal_credit} currency={currency} size="sm" />
                            </td>
                            <td className="py-2 pl-3">
                                <MoneyText minor={account.net} currency={currency} size="sm" />
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>
    );
}

/**
 * Transaction Detail by Account (Phase 7 Task 5): every posted line across
 * a chosen [from, to] period, grouped by the account it posted to, each
 * with its own running balance and a Subtotal row that ties to
 * LedgerBalances::movement -- an accountant screen (PROJECT_MAP §9 rule 8),
 * debit/credit columns shown as-is like General Ledger/Trial Balance.
 */
export default function TransactionDetail({ report, canExport }: TransactionDetailPageProps) {
    const { currentCompany } = usePage<PageProps>().props;
    const currency = currentCompany?.base_currency ?? 'USD';
    const [from, setFrom] = useState(report.from);
    const [to, setTo] = useState(report.to);

    function reload(nextFrom: string, nextTo: string) {
        setFrom(nextFrom);
        setTo(nextTo);

        if (!nextFrom || !nextTo) {
            return;
        }

        router.get(
            route('reports.transaction-detail'),
            { from: nextFrom, to: nextTo },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    const exportHref = `${route('reports.transaction-detail.export')}?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`;
    const isEmpty = report.accounts.length === 0;

    return (
        <AppShell title="Transaction detail by account">
            <Head title="Transaction detail by account" />

            <Sheet
                title="Transaction detail by account"
                actions={
                    <div className="flex flex-wrap items-center gap-2 print:hidden">
                        <label htmlFor="td-from" className="flex items-center gap-2 text-sm text-ink-700">
                            From
                            <input
                                id="td-from"
                                type="date"
                                value={from}
                                onChange={(event) => reload(event.target.value, to)}
                                className={INPUT_CLASSES}
                            />
                        </label>
                        <label htmlFor="td-to" className="flex items-center gap-2 text-sm text-ink-700">
                            To
                            <input
                                id="td-to"
                                type="date"
                                value={to}
                                onChange={(event) => reload(from, event.target.value)}
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
                {isEmpty ? (
                    <p className="text-sm text-ink-700">No activity for this period.</p>
                ) : (
                    <div className="flex flex-col gap-8">
                        {report.accounts.map((account) => (
                            <AccountSection key={account.account_id} account={account} currency={currency} />
                        ))}

                        <section className="flex items-center justify-between gap-4 border-t-2 border-rule pt-4">
                            <span className="font-display text-sm font-semibold text-ink">Grand total</span>
                            <div className="flex items-center gap-6">
                                <div className="flex items-center gap-2 text-sm text-ink-700">
                                    Debit
                                    <MoneyText minor={report.grand_total_debit} currency={currency} size="sm" className="font-semibold text-ink" />
                                </div>
                                <div className="flex items-center gap-2 text-sm text-ink-700">
                                    Credit
                                    <MoneyText minor={report.grand_total_credit} currency={currency} size="sm" className="font-semibold text-ink" />
                                </div>
                            </div>
                        </section>
                    </div>
                )}
            </Sheet>
        </AppShell>
    );
}
