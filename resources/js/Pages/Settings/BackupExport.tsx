import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

interface BackupStatus {
    available: boolean;
    filename?: string;
    last_backup_at?: string;
}

interface BackupExportPageProps extends PageProps {
    from: string;
    to: string;
    backupStatus: BackupStatus;
}

const INPUT_CLASSES =
    'h-9 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

const DOWNLOAD_LINK_CLASSES =
    'inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-gradient-to-r from-spark to-lagoon px-4 font-sans text-sm font-semibold text-white transition duration-[120ms] ease-out hover:brightness-105 active:brightness-95 focus:outline-none focus-visible:ring-2 focus-visible:ring-spark focus-visible:ring-offset-2 focus-visible:ring-offset-paper-raised motion-reduce:transition-none';

const FULL_EXPORT_CONTENTS = [
    'Accounts',
    'Contacts',
    'Items',
    'Journal entries & lines',
    'Invoices & invoice payments',
    'Bills & bill payments',
];

function formatDateTime(iso: string): string {
    return new Date(iso).toLocaleString('en-PK', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

/**
 * Settings > Backup & Export (spec §11, Phase 7 Task 7) — process-UI over
 * existing Phase-6 machinery, not a new export engine: (a) the same
 * Accountant Package (App\Services\AccountantPackageService) every
 * Reports/AccountantPackage.tsx download already produces, reachable from
 * here too; (b) a new full-data export escape hatch (raw per-table CSVs,
 * zipped); (c) a read-only glance at the last nightly backup
 * (BackupDatabase, app/Console/Commands) run on the server, or a calm note
 * when no local artifact is visible yet.
 */
export default function BackupExport({
    from: initialFrom,
    to: initialTo,
    backupStatus,
}: BackupExportPageProps) {
    const [from, setFrom] = useState(initialFrom);
    const [to, setTo] = useState(initialTo);

    const accountantPackageHref = `${route('settings.backup-export.accountant-package')}?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`;
    const fullExportHref = route('settings.backup-export.full-export');

    return (
        <AppShell title="Backup & export">
            <Head title="Backup & export" />

            <div className="flex flex-col gap-6">
                <Sheet
                    title="Accountant package"
                    actions={
                        <>
                            <label htmlFor="backup-export-from" className="flex items-center gap-2 text-sm text-ink-700">
                                From
                                <input
                                    id="backup-export-from"
                                    type="date"
                                    value={from}
                                    onChange={(event) => setFrom(event.target.value)}
                                    className={INPUT_CLASSES}
                                />
                            </label>
                            <label htmlFor="backup-export-to" className="flex items-center gap-2 text-sm text-ink-700">
                                To
                                <input
                                    id="backup-export-to"
                                    type="date"
                                    value={to}
                                    onChange={(event) => setTo(event.target.value)}
                                    className={INPUT_CLASSES}
                                />
                            </label>
                        </>
                    }
                >
                    <div className="flex flex-col gap-4">
                        <p className="text-sm text-ink-700">
                            General Ledger, Trial Balance, Profit &amp; Loss, Balance Sheet, A/R
                            aging and A/P aging for the selected period, each as a CSV and a PDF,
                            bundled into one zip — the same Accountant Package available from
                            Reports.
                        </p>
                        <div>
                            <a href={accountantPackageHref} className={DOWNLOAD_LINK_CLASSES}>
                                Download accountant package (.zip)
                            </a>
                        </div>
                    </div>
                </Sheet>

                <Sheet title="Full data export">
                    <div className="flex flex-col gap-4">
                        <p className="text-sm text-ink-700">
                            Every core record for this company — accounts, contacts, items,
                            journal entries and lines, invoices and their payments, bills and
                            their payments — as one zip of CSV files. This is your portability
                            escape hatch: a complete copy of your data, out of the app in one
                            click.
                        </p>

                        <ul className="grid grid-cols-2 gap-2 text-sm text-ink sm:grid-cols-3">
                            {FULL_EXPORT_CONTENTS.map((name) => (
                                <li key={name} className="rounded-md border border-rule bg-paper px-3 py-2">
                                    {name}
                                </li>
                            ))}
                        </ul>

                        <div>
                            <a href={fullExportHref} className={DOWNLOAD_LINK_CLASSES}>
                                Download full data export (.zip)
                            </a>
                        </div>
                    </div>
                </Sheet>

                <Sheet title="Backup status">
                    {backupStatus.available ? (
                        <div className="rounded-md border border-spark/30 bg-spark-50 p-4">
                            <p className="font-display text-lg font-semibold text-ink">
                                Last backup: {formatDateTime(backupStatus.last_backup_at!)}
                            </p>
                            <p className="mt-1 text-sm text-ink-700">
                                {backupStatus.filename}
                            </p>
                        </div>
                    ) : (
                        <p className="text-sm text-ink-700">
                            Backups run nightly on the server. No local backup status is visible
                            from here yet — this doesn&rsquo;t mean a backup hasn&rsquo;t run.
                        </p>
                    )}
                </Sheet>
            </div>
        </AppShell>
    );
}
