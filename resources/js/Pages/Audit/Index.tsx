import Sheet from '@/Components/ui/Sheet';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface AuditLogRow {
    id: number;
    /** ISO 8601. */
    created_at: string;
    user_id: number | null;
    user_name: string | null;
    /** Already humanized server-side (AuditHumanizer) — never the raw 'module.verb' code. */
    action: string;
    /** Humanized subject reference, e.g. "Invoice #12" — null when the action has no subject. */
    subject: string | null;
    ip: string | null;
}

interface UserOption {
    id: number;
    name: string;
}

interface AuditFilters {
    user_id: number | null;
    module: string | null;
    from: string | null;
    to: string | null;
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

interface AuditIndexPageProps extends PageProps {
    logs: Paginated<AuditLogRow>;
    filters: AuditFilters;
    users: UserOption[];
    modules: string[];
}

const INPUT_CLASSES =
    'h-9 rounded-md border border-rule bg-paper-raised px-3 text-sm text-ink transition duration-[120ms] ease-out motion-reduce:transition-none focus:outline-none focus:ring-2 focus:ring-spark';

/** 'bill_payment' -> "Bill payment". */
function humanizeModuleLabel(module: string): string {
    const words = module.replace(/_/g, ' ');
    return words.charAt(0).toUpperCase() + words.slice(1);
}

function formatDateTime(iso: string): string {
    return new Date(iso).toLocaleString('en-PK', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function Pagination({ logs }: { logs: Paginated<AuditLogRow> }) {
    if (logs.last_page <= 1) {
        return null;
    }

    return (
        <nav aria-label="Audit log pages" className="mt-4 flex items-center justify-center gap-1">
            {logs.links.map((link, index) => {
                const baseClasses =
                    'flex h-8 min-w-8 items-center justify-center rounded-md px-2 text-sm transition duration-[120ms] ease-out motion-reduce:transition-none ';

                // Laravel's own paginator supplies these labels server-side —
                // never user input — so rendering the HTML entity is safe.
                const label = { __html: link.label };

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
                        preserveState
                        aria-current={link.active ? 'page' : undefined}
                        className={
                            baseClasses +
                            (link.active ? 'bg-ink text-white' : 'text-ink-700 hover:bg-paper')
                        }
                        dangerouslySetInnerHTML={label}
                    />
                );
            })}
        </nav>
    );
}

/**
 * Phase 6 Task 2 (spec §7.13) — READ-ONLY viewer over the append-only
 * activity_log (AuditLogController). A general audit screen, not an
 * accountant one — no debit/credit words, design tokens only (PROJECT_MAP
 * §9 rule 8). The list never carries the row's raw before/after JSON diff;
 * see AuditLogController's own class docblock for why.
 */
export default function Index({ logs, filters, users, modules }: AuditIndexPageProps) {
    const [userId, setUserId] = useState(filters.user_id !== null ? String(filters.user_id) : '');
    const [module, setModule] = useState(filters.module ?? '');
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');

    function reload(next: { user_id?: string; module?: string; from?: string; to?: string }) {
        const nextUserId = next.user_id ?? userId;
        const nextModule = next.module ?? module;
        const nextFrom = next.from ?? from;
        const nextTo = next.to ?? to;

        setUserId(nextUserId);
        setModule(nextModule);
        setFrom(nextFrom);
        setTo(nextTo);

        const query: Record<string, string> = {};
        if (nextUserId) {
            query.user_id = nextUserId;
        }
        if (nextModule) {
            query.module = nextModule;
        }
        if (nextFrom) {
            query.from = nextFrom;
        }
        if (nextTo) {
            query.to = nextTo;
        }

        router.get(route('settings.audit-log'), query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    const hasFilters = userId !== '' || module !== '' || from !== '' || to !== '';
    const isEmpty = logs.data.length === 0;

    return (
        <AppShell title="Audit log">
            <Head title="Audit log" />

            <Sheet title="Audit log">
                <div className="mb-4 flex flex-wrap items-end gap-3">
                    <label htmlFor="audit-user" className="flex flex-col gap-1.5 text-sm text-ink-700">
                        User
                        <select
                            id="audit-user"
                            value={userId}
                            onChange={(event) => reload({ user_id: event.target.value })}
                            className={INPUT_CLASSES}
                        >
                            <option value="">All users</option>
                            {users.map((user) => (
                                <option key={user.id} value={user.id}>
                                    {user.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label htmlFor="audit-module" className="flex flex-col gap-1.5 text-sm text-ink-700">
                        Module
                        <select
                            id="audit-module"
                            value={module}
                            onChange={(event) => reload({ module: event.target.value })}
                            className={INPUT_CLASSES}
                        >
                            <option value="">All modules</option>
                            {modules.map((option) => (
                                <option key={option} value={option}>
                                    {humanizeModuleLabel(option)}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label htmlFor="audit-from" className="flex flex-col gap-1.5 text-sm text-ink-700">
                        From
                        <input
                            id="audit-from"
                            type="date"
                            value={from}
                            onChange={(event) => reload({ from: event.target.value })}
                            className={INPUT_CLASSES}
                        />
                    </label>
                    <label htmlFor="audit-to" className="flex flex-col gap-1.5 text-sm text-ink-700">
                        To
                        <input
                            id="audit-to"
                            type="date"
                            value={to}
                            onChange={(event) => reload({ to: event.target.value })}
                            className={INPUT_CLASSES}
                        />
                    </label>
                    {hasFilters && (
                        <button
                            type="button"
                            onClick={() => reload({ user_id: '', module: '', from: '', to: '' })}
                            className="h-9 font-sans text-sm font-medium text-lagoon underline decoration-dotted underline-offset-4 hover:no-underline"
                        >
                            Clear filters
                        </button>
                    )}
                </div>

                {isEmpty ? (
                    <p className="py-8 text-center text-sm text-ink-700">
                        {hasFilters ? 'No activity matches this filter.' : 'No activity recorded yet.'}
                    </p>
                ) : (
                    <>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-max border-collapse text-left text-sm">
                                <thead>
                                    <tr className="border-b border-rule text-xs font-medium uppercase tracking-wide text-ink-700">
                                        <th scope="col" className="py-2 pr-3 font-medium">Timestamp</th>
                                        <th scope="col" className="py-2 pr-3 font-medium">User</th>
                                        <th scope="col" className="py-2 pr-3 font-medium">Action</th>
                                        <th scope="col" className="py-2 pr-3 font-medium">Subject</th>
                                        <th scope="col" className="py-2 pl-3 font-medium">IP address</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {logs.data.map((log) => (
                                        <tr key={log.id} className="border-b border-rule text-ink last:border-b-0">
                                            <td className="py-3 pr-3 align-top text-ink-700">
                                                {formatDateTime(log.created_at)}
                                            </td>
                                            <td className="py-3 pr-3 align-top">{log.user_name ?? '—'}</td>
                                            <td className="py-3 pr-3 align-top">{log.action}</td>
                                            <td className="py-3 pr-3 align-top text-ink-700">
                                                {log.subject ?? '—'}
                                            </td>
                                            <td className="py-3 pl-3 align-top font-mono text-xs text-ink-700">
                                                {log.ip ?? '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <Pagination logs={logs} />
                    </>
                )}
            </Sheet>
        </AppShell>
    );
}
