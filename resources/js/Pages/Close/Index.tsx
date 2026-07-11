import Button from '@/Components/ui/Button';
import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import {
    Dialog,
    DialogBackdrop,
    DialogPanel,
    DialogTitle,
} from '@headlessui/react';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface OutstandingAccount {
    id: number;
    name: string;
}

interface ReconcileStep {
    done: boolean;
    outstanding_accounts: OutstandingAccount[];
}

interface AcknowledgeStep {
    done: boolean;
    reviewed_at: string | null;
}

interface DraftItem {
    type: 'invoice' | 'bill' | 'expense';
    id: number;
    label: string;
    date: string;
}

interface DraftsStep {
    done: boolean;
    drafts: DraftItem[];
}

interface Finding {
    check: string;
    detail: string;
}

interface IntegrityStep {
    done: boolean;
    ran_at: string | null;
    findings: Finding[];
}

interface LockStep {
    done: boolean;
    locked_through: string | null;
}

interface ExportStep {
    done: boolean;
}

interface Steps {
    reconcile: ReconcileStep;
    ar_ap_aging: AcknowledgeStep;
    trial_balance: AcknowledgeStep;
    drafts: DraftsStep;
    integrity: IntegrityStep;
    lock: LockStep;
    export: ExportStep;
}

interface ClosePageProps extends PageProps {
    monthEnd: string;
    steps: Steps;
    completedAt: string | null;
    exportRange: { from: string; to: string };
}

const INPUT_CLASSES =
    'h-9 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

const DRAFT_ROUTE: Record<DraftItem['type'], string> = {
    invoice: 'invoices.show',
    bill: 'bills.show',
    expense: 'expenses.show',
};

function StatusPill({ done }: { done: boolean }) {
    return (
        <span
            className={
                'inline-flex h-6 items-center rounded-full px-3 text-xs font-semibold ' +
                (done
                    ? 'bg-spark-50 text-spark border border-spark/30'
                    : 'bg-amber-50 text-amber border border-amber/30')
            }
        >
            {done ? 'Done' : 'Not done'}
        </span>
    );
}

function StepCard({
    number,
    title,
    done,
    description,
    children,
}: {
    number: number;
    title: string;
    done: boolean;
    description: string;
    children?: React.ReactNode;
}) {
    return (
        <div className="rounded-md border border-rule bg-paper p-4">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wide text-ink-700">
                        Step {number}
                    </p>
                    <h3 className="mt-0.5 font-display text-base font-semibold text-ink">
                        {title}
                    </h3>
                    <p className="mt-1 text-sm text-ink-700">{description}</p>
                </div>
                <StatusPill done={done} />
            </div>
            {children && <div className="mt-4">{children}</div>}
        </div>
    );
}

/**
 * The month-end close checklist (spec §17.5, Phase 6 Task 5) — a guided
 * screen over existing machinery. Steps 1/4/5/6 reflect real system state
 * (reconciliations, drafts, the integrity checker, the period lock); steps
 * 2/3 are a manual "I reviewed this" acknowledge; step 7 links to the T4
 * accountant package for the now-frozen period. Every step is revisitable —
 * including the lock, which can be moved again from here or from
 * Settings > Books.
 */
export default function CloseIndex({
    monthEnd: initialMonthEnd,
    steps,
    completedAt,
    exportRange,
}: ClosePageProps) {
    const [monthEnd, setMonthEnd] = useState(initialMonthEnd);
    const [pending, setPending] = useState<string | null>(null);
    const [confirmAction, setConfirmAction] = useState<'lock' | 'complete' | null>(null);

    const doneCount = Object.values(steps).filter((step) => step.done).length;
    const totalSteps = Object.keys(steps).length;

    function reload(nextMonthEnd: string) {
        setMonthEnd(nextMonthEnd);
        if (!nextMonthEnd) {
            return;
        }
        router.get(
            route('close.show'),
            { month_end: nextMonthEnd },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function post(action: string, url: string, data: Record<string, unknown> = {}) {
        if (pending) {
            return;
        }
        setPending(action);
        router.post(
            url,
            { month_end: monthEnd, ...data },
            {
                preserveScroll: true,
                onFinish: () => setPending(null),
                onSuccess: () => setConfirmAction(null),
            },
        );
    }

    const toggleAcknowledge = (step: 'ar_ap_aging' | 'trial_balance', acknowledged: boolean) =>
        post(`ack-${step}`, route('close.acknowledge'), { step, acknowledged });

    const runIntegrityCheck = () => post('integrity', route('close.integrity-check'));

    const confirmLock = () => setConfirmAction('lock');
    const confirmComplete = () => setConfirmAction('complete');
    const closeConfirm = () => setConfirmAction(null);

    const runLock = () => post('lock', route('close.lock'));
    const runComplete = () => post('complete', route('close.complete'));

    const exportHref = `${route('reports.accountant-package')}?from=${encodeURIComponent(exportRange.from)}&to=${encodeURIComponent(exportRange.to)}`;

    return (
        <AppShell title="Month-end close">
            <Head title="Month-end close" />

            <div className="flex flex-col gap-6">
                <Sheet
                    title="Month-end close"
                    actions={
                        <label htmlFor="close-month-end" className="flex items-center gap-2 text-sm text-ink-700">
                            Month end
                            <input
                                id="close-month-end"
                                type="date"
                                value={monthEnd}
                                onChange={(event) => reload(event.target.value)}
                                className={INPUT_CLASSES}
                            />
                        </label>
                    }
                >
                    <div className="flex flex-col gap-2">
                        <p className="text-sm text-ink-700">
                            Work through all seven steps to close the books through{' '}
                            <span className="font-semibold text-ink">{monthEnd}</span>. Every step
                            except the lock can be revisited at any time.
                        </p>
                        <p className="text-sm font-semibold text-ink">
                            {doneCount} of {totalSteps} steps done
                        </p>
                        {completedAt && (
                            <p className="text-sm text-spark">
                                Close completed {new Date(completedAt).toLocaleString('en-PK', { dateStyle: 'medium', timeStyle: 'short' })}
                            </p>
                        )}
                    </div>
                </Sheet>

                <div className="flex flex-col gap-4">
                    <StepCard
                        number={1}
                        title="Reconcile all bank &amp; cash accounts"
                        done={steps.reconcile.done}
                        description="Every active bank/cash account must have a completed reconciliation through the month end."
                    >
                        <div className="flex flex-col gap-2">
                            <Link href={route('reconciliations.index')} className="text-sm font-semibold text-lagoon hover:underline">
                                Go to Reconciliation →
                            </Link>
                            {!steps.reconcile.done && steps.reconcile.outstanding_accounts.length > 0 && (
                                <ul className="mt-1 flex flex-col gap-1">
                                    {steps.reconcile.outstanding_accounts.map((account) => (
                                        <li key={account.id} className="text-sm text-amber">
                                            {account.name} is not reconciled through {monthEnd}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </StepCard>

                    <StepCard
                        number={2}
                        title="Review A/R and A/P aging"
                        done={steps.ar_ap_aging.done}
                        description="Read the aging reports for anything overdue or unusual before closing."
                    >
                        <div className="flex flex-wrap items-center gap-4">
                            <Link href={route('reports.ar-aging')} className="text-sm font-semibold text-lagoon hover:underline">
                                A/R aging →
                            </Link>
                            <Link href={route('reports.ap-aging')} className="text-sm font-semibold text-lagoon hover:underline">
                                A/P aging →
                            </Link>
                            <label className="flex items-center gap-2 text-sm text-ink-700">
                                <input
                                    type="checkbox"
                                    checked={steps.ar_ap_aging.done}
                                    onChange={(event) => toggleAcknowledge('ar_ap_aging', event.target.checked)}
                                    disabled={pending === 'ack-ar_ap_aging'}
                                    className="h-4 w-4 rounded border-rule text-spark focus:ring-spark"
                                />
                                I&rsquo;ve reviewed A/R and A/P aging
                            </label>
                        </div>
                    </StepCard>

                    <StepCard
                        number={3}
                        title="Review the Trial Balance"
                        done={steps.trial_balance.done}
                        description="Confirm every account balance looks right before locking the period."
                    >
                        <div className="flex flex-wrap items-center gap-4">
                            <Link href={route('reports.trial-balance')} className="text-sm font-semibold text-lagoon hover:underline">
                                Trial Balance →
                            </Link>
                            <label className="flex items-center gap-2 text-sm text-ink-700">
                                <input
                                    type="checkbox"
                                    checked={steps.trial_balance.done}
                                    onChange={(event) => toggleAcknowledge('trial_balance', event.target.checked)}
                                    disabled={pending === 'ack-trial_balance'}
                                    className="h-4 w-4 rounded border-rule text-spark focus:ring-spark"
                                />
                                I&rsquo;ve reviewed the Trial Balance
                            </label>
                        </div>
                    </StepCard>

                    <StepCard
                        number={4}
                        title="Sweep unposted drafts"
                        done={steps.drafts.done}
                        description="Every draft invoice, bill, or expense dated on or before the month end must be sent/recorded or re-dated — otherwise the lock strands it."
                    >
                        {steps.drafts.drafts.length === 0 ? (
                            <p className="text-sm text-ink-700">No outstanding drafts dated on or before {monthEnd}.</p>
                        ) : (
                            <ul className="flex flex-col gap-1">
                                {steps.drafts.drafts.map((draft) => (
                                    <li key={`${draft.type}-${draft.id}`} className="flex items-center gap-2 text-sm">
                                        <span className="rounded bg-amber-50 px-2 py-0.5 text-xs font-semibold uppercase text-amber">
                                            {draft.type}
                                        </span>
                                        <Link href={route(DRAFT_ROUTE[draft.type], draft.id)} className="font-semibold text-lagoon hover:underline">
                                            {draft.label}
                                        </Link>
                                        <span className="text-ink-700">dated {draft.date}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </StepCard>

                    <StepCard
                        number={5}
                        title="Run the integrity checker"
                        done={steps.integrity.done}
                        description="Runs the same §9 correctness watchdog as Settings > Health — must report zero findings."
                    >
                        <div className="flex flex-col gap-3">
                            <div>
                                <Button size="sm" onClick={runIntegrityCheck} disabled={pending === 'integrity'}>
                                    {pending === 'integrity' ? 'Running…' : 'Run check'}
                                </Button>
                            </div>
                            {steps.integrity.ran_at === null && (
                                <p className="text-sm text-ink-700">No integrity check has run yet.</p>
                            )}
                            {steps.integrity.ran_at !== null && steps.integrity.done && (
                                <p className="text-sm text-ink-700">
                                    Last checked {new Date(steps.integrity.ran_at).toLocaleString('en-PK', { dateStyle: 'medium', timeStyle: 'short' })} — all checks passed.
                                </p>
                            )}
                            {steps.integrity.ran_at !== null && !steps.integrity.done && (
                                <ul className="flex flex-col gap-2">
                                    {steps.integrity.findings.map((finding, index) => (
                                        <li key={index} className="rounded-md border border-clay/30 bg-clay-50 p-3">
                                            <p className="text-xs font-semibold uppercase tracking-wide text-ink-700">{finding.check}</p>
                                            <p className="mt-0.5 text-sm text-ink">{finding.detail}</p>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </StepCard>

                    <StepCard
                        number={6}
                        title="Lock the period"
                        done={steps.lock.done}
                        description="Freezes every entry dated on or before the month end. The one irreversible-ish step — though even this can be moved later by an Owner or Accountant."
                    >
                        <div className="flex items-center gap-4">
                            <Button size="sm" onClick={confirmLock} disabled={pending === 'lock'}>
                                Lock through {monthEnd}
                            </Button>
                            <span className="text-sm text-ink-700">
                                {steps.lock.locked_through
                                    ? `Currently locked through ${steps.lock.locked_through}`
                                    : 'Not locked yet'}
                            </span>
                        </div>
                    </StepCard>

                    <StepCard
                        number={7}
                        title="Export the accountant package"
                        done={steps.export.done}
                        description="Export after the lock, so the package matches exactly what the lock froze."
                    >
                        <a
                            href={exportHref}
                            className="text-sm font-semibold text-lagoon hover:underline"
                        >
                            Accountant package ({exportRange.from} – {exportRange.to}) →
                        </a>
                    </StepCard>
                </div>

                <Sheet>
                    <div className="flex items-center justify-between gap-4">
                        <p className="text-sm text-ink-700">
                            Once every step looks right, mark the close complete. This is audit-logged and can be
                            done again later if you need to.
                        </p>
                        <Button onClick={confirmComplete} disabled={pending === 'complete'}>
                            Complete close
                        </Button>
                    </div>
                </Sheet>
            </div>

            {confirmAction && (
                <Dialog open onClose={closeConfirm} className="relative z-50">
                    <DialogBackdrop className="fixed inset-0 bg-ink/30" />
                    <div className="fixed inset-0 flex items-center justify-center p-4">
                        <DialogPanel className="w-full max-w-md rounded-sheet bg-paper-raised p-6 shadow-sheet">
                            <DialogTitle className="font-display text-lg font-semibold text-ink">
                                {confirmAction === 'lock' ? 'Lock the books?' : 'Complete the close?'}
                            </DialogTitle>
                            <p className="mt-2 text-sm text-ink-700">
                                {confirmAction === 'lock'
                                    ? `This freezes every entry dated on or before ${monthEnd}. Nobody will be able to add, edit, or reverse into that period until the lock is moved.`
                                    : `This marks the close for ${monthEnd} complete and audit-logs it. You can revisit any step afterward.`}
                            </p>
                            <div className="mt-6 flex justify-end gap-2">
                                <Button type="button" variant="quiet" onClick={closeConfirm}>
                                    Cancel
                                </Button>
                                <Button
                                    type="button"
                                    onClick={confirmAction === 'lock' ? runLock : runComplete}
                                    disabled={pending === 'lock' || pending === 'complete'}
                                >
                                    {pending === 'lock' || pending === 'complete'
                                        ? 'Saving…'
                                        : confirmAction === 'lock'
                                          ? 'Lock'
                                          : 'Complete'}
                                </Button>
                            </div>
                        </DialogPanel>
                    </div>
                </Dialog>
            )}
        </AppShell>
    );
}
