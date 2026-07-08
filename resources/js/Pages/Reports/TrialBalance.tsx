import BalanceBeam from '@/Components/ledger/BalanceBeam';
import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface TrialBalanceRow {
    account_id: number;
    code: string;
    name: string;
    type: string;
    debit: number;
    credit: number;
}

interface TrialBalanceReportData {
    rows: TrialBalanceRow[];
    total_debit: number;
    total_credit: number;
    as_of: string;
}

interface TrialBalancePageProps extends PageProps {
    report: TrialBalanceReportData;
    canExport: boolean;
}

const INPUT_CLASSES =
    'h-9 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

/**
 * Gate-B accountant sign-off report: for every account with activity as of a
 * chosen date, shows its net debit or net credit column. Enforces (and
 * visually confirms via BalanceBeam) the §12.9 invariant — total debits must
 * always equal total credits.
 */
export default function TrialBalance({ report, canExport }: TrialBalancePageProps) {
    const { currentCompany } = usePage<PageProps>().props;
    const currency = currentCompany?.base_currency ?? 'USD';
    const [asOf, setAsOf] = useState(report.as_of);

    function reload(nextAsOf: string) {
        setAsOf(nextAsOf);

        if (!nextAsOf) {
            return;
        }

        router.get(
            route('reports.trial-balance'),
            { as_of: nextAsOf },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    const exportHref = `${route('reports.trial-balance.export')}?as_of=${encodeURIComponent(asOf)}`;

    return (
        <AppShell title="Trial balance">
            <Head title="Trial balance" />

            <Sheet
                title="Trial balance"
                actions={
                    <>
                        <label
                            htmlFor="trial-balance-as-of"
                            className="flex items-center gap-2 text-sm text-ink-700"
                        >
                            As of
                            <input
                                id="trial-balance-as-of"
                                type="date"
                                value={asOf}
                                onChange={(event) => reload(event.target.value)}
                                className={INPUT_CLASSES}
                            />
                        </label>
                        {canExport && (
                            <a
                                href={exportHref}
                                className="inline-flex h-8 items-center justify-center gap-2 rounded-lg px-3 font-sans text-xs font-semibold text-ink-700 transition duration-[120ms] ease-out hover:bg-paper focus:outline-none focus-visible:ring-2 focus-visible:ring-spark focus-visible:ring-offset-2 focus-visible:ring-offset-paper-raised motion-reduce:transition-none"
                            >
                                Export CSV
                            </a>
                        )}
                    </>
                }
            >
                {report.rows.length === 0 ? (
                    <p className="text-sm text-ink-700">
                        No entries yet for this date.
                    </p>
                ) : (
                    <div className="flex flex-col gap-6">
                        <table className="w-full border-collapse text-left text-sm">
                            <thead>
                                <tr className="border-b border-rule text-xs font-medium uppercase tracking-wide text-ink-700">
                                    <th scope="col" className="py-2 pr-3 font-medium">
                                        Code
                                    </th>
                                    <th scope="col" className="py-2 pr-3 font-medium">
                                        Account
                                    </th>
                                    <th scope="col" className="py-2 pl-3 text-right font-medium">
                                        Debit
                                    </th>
                                    <th scope="col" className="py-2 pl-3 text-right font-medium">
                                        Credit
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {report.rows.map((row) => (
                                    <tr
                                        key={row.account_id}
                                        className="border-b border-rule text-ink last:border-b-0"
                                    >
                                        <td className="py-3 pr-3 align-top font-mono">
                                            {row.code}
                                        </td>
                                        <td className="py-3 pr-3 align-top">{row.name}</td>
                                        <td className="py-3 pl-3 align-top">
                                            <MoneyText
                                                minor={row.debit}
                                                currency={currency}
                                                size="sm"
                                            />
                                        </td>
                                        <td className="py-3 pl-3 align-top">
                                            <MoneyText
                                                minor={row.credit}
                                                currency={currency}
                                                size="sm"
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr className="border-t-2 border-rule font-semibold text-ink">
                                    <td className="py-3 pr-3" colSpan={2}>
                                        Totals
                                    </td>
                                    <td className="py-3 pl-3">
                                        <MoneyText
                                            minor={report.total_debit}
                                            currency={currency}
                                            size="sm"
                                        />
                                    </td>
                                    <td className="py-3 pl-3">
                                        <MoneyText
                                            minor={report.total_credit}
                                            currency={currency}
                                            size="sm"
                                        />
                                    </td>
                                </tr>
                            </tfoot>
                        </table>

                        <div className="max-w-xs">
                            <BalanceBeam
                                debitTotal={report.total_debit}
                                creditTotal={report.total_credit}
                                currency={currency}
                            />
                        </div>
                    </div>
                )}
            </Sheet>
        </AppShell>
    );
}
