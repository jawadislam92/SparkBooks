import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

interface AccountantPackagePageProps extends PageProps {
    from: string;
    to: string;
    canExport: boolean;
}

const INPUT_CLASSES =
    'h-9 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

const PACKAGE_CONTENTS = [
    'General Ledger',
    'Trial Balance',
    'Profit & Loss',
    'Balance Sheet',
    'A/R Aging',
    'A/P Aging',
];

/**
 * The Accountant Package screen (spec §11, Phase 6 Task 4) — "one-click ...
 * This is also the escape hatch": lets an Owner/Admin/Accountant pick a
 * period and download every Gate-B report artifact (six reports, each as a
 * CSV and a PDF) in a single zip. Mirrors the from/to period picker every
 * other range-based report (e.g. Reports/ProfitAndLoss.tsx) already uses.
 */
export default function AccountantPackage({ from: initialFrom, to: initialTo, canExport }: AccountantPackagePageProps) {
    const [from, setFrom] = useState(initialFrom);
    const [to, setTo] = useState(initialTo);

    function reload(nextFrom: string, nextTo: string) {
        setFrom(nextFrom);
        setTo(nextTo);

        if (!nextFrom || !nextTo) {
            return;
        }

        router.get(
            route('reports.accountant-package'),
            { from: nextFrom, to: nextTo },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    const downloadHref = `${route('reports.accountant-package.download')}?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`;

    return (
        <AppShell title="Accountant package">
            <Head title="Accountant package" />

            <Sheet
                title="Accountant package"
                actions={
                    <>
                        <label htmlFor="accountant-package-from" className="flex items-center gap-2 text-sm text-ink-700">
                            From
                            <input
                                id="accountant-package-from"
                                type="date"
                                value={from}
                                onChange={(event) => reload(event.target.value, to)}
                                className={INPUT_CLASSES}
                            />
                        </label>
                        <label htmlFor="accountant-package-to" className="flex items-center gap-2 text-sm text-ink-700">
                            To
                            <input
                                id="accountant-package-to"
                                type="date"
                                value={to}
                                onChange={(event) => reload(from, event.target.value)}
                                className={INPUT_CLASSES}
                            />
                        </label>
                    </>
                }
            >
                <div className="flex flex-col gap-6">
                    <p className="text-sm text-ink-700">
                        One zip with the General Ledger, Trial Balance, Profit &amp; Loss, Balance
                        Sheet, A/R Aging and A/P Aging for the selected period — each as a CSV and
                        a PDF. Every number ties back to the ledger. This is your escape hatch:
                        everything your accountant needs, out of the app in one click.
                    </p>

                    <ul className="grid grid-cols-2 gap-2 text-sm text-ink sm:grid-cols-3">
                        {PACKAGE_CONTENTS.map((name) => (
                            <li key={name} className="rounded-md border border-rule bg-paper px-3 py-2">
                                {name}
                            </li>
                        ))}
                    </ul>

                    {canExport ? (
                        <div>
                            <a
                                href={downloadHref}
                                className="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-gradient-to-r from-spark to-lagoon px-4 font-sans text-sm font-semibold text-white transition duration-[120ms] ease-out hover:brightness-105 active:brightness-95 focus:outline-none focus-visible:ring-2 focus-visible:ring-spark focus-visible:ring-offset-2 focus-visible:ring-offset-paper-raised motion-reduce:transition-none"
                            >
                                Download accountant package (.zip)
                            </a>
                        </div>
                    ) : (
                        <p className="text-sm text-ink-700">
                            You don&rsquo;t have permission to export the accountant package.
                        </p>
                    )}
                </div>
            </Sheet>
        </AppShell>
    );
}
