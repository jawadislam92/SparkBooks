import Sheet from '@/Components/ui/Sheet';

export interface RecentActivityItem {
    id: number;
    /** Already humanized server-side (DashboardController::humanizeAction) — never a raw action code. */
    action: string;
    /** Already humanized server-side (e.g. "Invoice #12") — null when the action has no subject. */
    subject: string | null;
    /** Relative time (Carbon::diffForHumans()), e.g. "2 hours ago". */
    when: string;
    created_at: string;
}

interface RecentActivityCardProps {
    items: RecentActivityItem[];
}

/**
 * The Dashboard's recent-activity widget (spec §7.12) — gated server-side on
 * `audit.view` (DashboardController::recentActivity() returns an empty list
 * for anyone else, never a 403 — the widget just doesn't render). Shows a
 * humanized action + subject + relative time only; the raw before/after
 * audit JSON never reaches this component at all.
 */
export default function RecentActivityCard({ items }: RecentActivityCardProps) {
    if (items.length === 0) {
        return null;
    }

    return (
        <Sheet title="Recent activity">
            <ul className="divide-y divide-rule">
                {items.map((item) => (
                    <li
                        key={item.id}
                        className="flex items-center justify-between gap-3 py-2 text-sm first:pt-0 last:pb-0"
                    >
                        <div>
                            <p className="text-ink">{item.action}</p>
                            {item.subject && <p className="text-xs text-ink-700">{item.subject}</p>}
                        </div>
                        <time dateTime={item.created_at} className="shrink-0 text-xs text-ink-700">
                            {item.when}
                        </time>
                    </li>
                ))}
            </ul>
        </Sheet>
    );
}
