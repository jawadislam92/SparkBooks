import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface BalanceContactRow {
    contact_id: number;
    name: string;
    balance: number;
}

interface ControlAccount {
    account_id: number;
    code: string;
    name: string;
}

interface VendorBalanceSummaryData {
    as_of: string;
    control_account: ControlAccount | null;
    control_balance: number;
    contacts: BalanceContactRow[];
    grand_total: number;
    ties_to_control: boolean;
    difference: number;
}

interface VendorBalanceSummaryPageProps extends PageProps {
    report: VendorBalanceSummaryData;
    canExport: boolean;
}

const INPUT_CLASSES =
    'h-9 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

const TIE_OUT_NOTE =
    'Every dollar shown here is drawn from the same ledger balance as the Accounts Payable control account — this total is always expected to match it exactly.';

/**
 * Vendor Balance Summary — the AP-side mirror of CustomerBalanceSummary,
 * tied to the 2000 control account (ContactLedgerReport::vendorBalanceSummary).
 */
export default function VendorBalanceSummary({ report, canExport }: VendorBalanceSummaryPageProps) {
    const { currentCompany } = usePage<PageProps>().props;
    const currency = currentCompany?.base_currency ?? 'USD';
    const [asOf, setAsOf] = useState(report.as_of);

    function reload(nextAsOf: string) {
        setAsOf(nextAsOf);
        if (!nextAsOf) {
            return;
        }
        router.get(route('reports.vendor-balance-summary'), { as_of: nextAsOf }, { preserveState: true, preserveScroll: true, replace: true });
    }

    const exportHref = `${route('reports.vendor-balance-summary.export')}?as_of=${encodeURIComponent(asOf)}`;
    const isEmpty = report.contacts.length === 0;

    return (
        <AppShell title="Vendor balances">
            <Head title="Vendor balances" />

            <Sheet
                title="Vendor balances"
                actions={
                    <div className="flex items-center gap-2 print:hidden">
                        <label htmlFor="vbs-as-of" className="flex items-center gap-2 text-sm text-ink-700">
                            As of
                            <input
                                id="vbs-as-of"
                                type="date"
                                value={asOf}
                                onChange={(event) => reload(event.target.value)}
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
                    <p className="text-sm text-ink-700">No outstanding vendor balances as of this date.</p>
                ) : (
                    <div className="flex flex-col gap-4">
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-max border-collapse text-left text-sm">
                                <thead>
                                    <tr className="border-b border-rule text-xs font-medium uppercase tracking-wide text-ink-700">
                                        <th scope="col" className="py-2 pr-3 font-medium">Vendor</th>
                                        <th scope="col" className="py-2 pl-3 text-right font-medium">Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {report.contacts.map((row) => (
                                        <tr key={row.contact_id} className="border-b border-rule text-ink last:border-b-0">
                                            <td className="py-2 pr-3 align-top">
                                                <Link
                                                    href={`${route('reports.vendor-balance-detail')}?contact_id=${row.contact_id}&as_of=${encodeURIComponent(report.as_of)}`}
                                                    className="text-spark underline decoration-dotted underline-offset-4 hover:no-underline"
                                                >
                                                    {row.name}
                                                </Link>
                                            </td>
                                            <td className="py-2 pl-3 align-top">
                                                <MoneyText minor={row.balance} currency={currency} size="sm" className="font-semibold" />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot>
                                    <tr className="border-t-2 border-rule font-semibold text-ink">
                                        <td className="py-2 pr-3">Total</td>
                                        <td className="py-2 pl-3">
                                            <MoneyText minor={report.grand_total} currency={currency} size="sm" />
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <div className="flex items-center justify-between gap-4 rounded-md bg-paper px-3 py-2 print:hidden">
                            <span title={TIE_OUT_NOTE} className="cursor-help text-sm font-medium text-ink underline decoration-dotted underline-offset-4">
                                {report.ties_to_control ? 'Ties to control account' : 'Does not tie to control account'}
                            </span>
                            {report.ties_to_control ? (
                                <span className="text-sm font-semibold text-spark">✓</span>
                            ) : (
                                <MoneyText minor={report.difference} currency={currency} size="sm" className="font-semibold text-clay" />
                            )}
                        </div>
                    </div>
                )}
            </Sheet>
        </AppShell>
    );
}
