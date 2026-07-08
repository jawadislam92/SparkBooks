import Button from '@/Components/ui/Button';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, useForm } from '@inertiajs/react';

interface Finding {
    check: string;
    detail: string;
}

interface LatestRun {
    ranAt: string;
    clean: boolean;
    findings: Finding[];
}

interface HealthPageProps extends PageProps {
    latestRun: LatestRun | null;
}

function formatRanAt(ranAt: string): string {
    return new Date(ranAt).toLocaleString('en-PK', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

/**
 * Settings > Health — the §9 correctness watchdog's on-demand screen. Shows
 * the latest integrity_runs row for the current company and lets an
 * Owner/Accountant (audit.view) trigger a synchronous re-check. The same
 * checks also run daily via `ledger:verify` (routes/console.php).
 */
export default function Health({ latestRun }: HealthPageProps) {
    const form = useForm({});

    const runNow = () => {
        if (form.processing) {
            return;
        }
        form.post(route('settings.health.run'), { preserveScroll: true });
    };

    return (
        <AppShell title="Ledger health">
            <Head title="Ledger health" />

            <Sheet
                title="Integrity check"
                actions={
                    <Button onClick={runNow} disabled={form.processing}>
                        {form.processing ? 'Running…' : 'Run now'}
                    </Button>
                }
            >
                <div className="flex flex-col gap-4">
                    {latestRun === null && (
                        <p className="text-sm text-ink-700">
                            No integrity check has run yet for this company.
                            Click &ldquo;Run now&rdquo; to check it for the
                            first time.
                        </p>
                    )}

                    {latestRun !== null && latestRun.clean && (
                        <div className="rounded-md border border-spark/30 bg-spark-50 p-4">
                            <p className="font-display text-lg font-semibold text-ink">
                                All checks passed
                            </p>
                            <p className="mt-1 text-sm text-ink-700">
                                Last checked {formatRanAt(latestRun.ranAt)}
                            </p>
                        </div>
                    )}

                    {latestRun !== null && !latestRun.clean && (
                        <div className="flex flex-col gap-4">
                            <div className="rounded-md border border-clay/30 bg-clay-50 p-4">
                                <p className="font-display text-lg font-semibold text-clay">
                                    Integrity findings detected
                                </p>
                                <p className="mt-1 text-sm text-ink-700">
                                    Last checked{' '}
                                    {formatRanAt(latestRun.ranAt)}
                                </p>
                            </div>

                            <ul className="flex flex-col gap-2">
                                {latestRun.findings.map((finding, index) => (
                                    <li
                                        key={index}
                                        className="rounded-md border border-amber/30 bg-amber-50 p-3"
                                    >
                                        <p className="text-xs font-semibold uppercase tracking-wide text-ink-700">
                                            {finding.check}
                                        </p>
                                        <p className="mt-0.5 text-sm text-ink">
                                            {finding.detail}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            </Sheet>
        </AppShell>
    );
}
