import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import { Link } from '@inertiajs/react';

interface DocumentSummaryCardProps {
    title: string;
    /** Singular noun, e.g. "invoice" / "bill" — pluralized with a trailing "s" for the count line. */
    noun: string;
    outstandingCount: number;
    outstandingTotal: number;
    overdueCount: number;
    overdueTotal: number;
    currency: string;
    href: string;
    linkLabel: string;
}

function pluralize(count: number, noun: string): string {
    return `${count} ${noun}${count === 1 ? '' : 's'}`;
}

/**
 * Shared shape for the Dashboard's "Outstanding & overdue invoices" and
 * "Unpaid bills" widgets (spec §7.12) — same card, different noun/figures.
 * The overdue subset only gets its own (amber) callout when it's non-zero,
 * so a company with no overdue documents doesn't show an empty "0 overdue"
 * box competing for attention.
 */
export default function DocumentSummaryCard({
    title,
    noun,
    outstandingCount,
    outstandingTotal,
    overdueCount,
    overdueTotal,
    currency,
    href,
    linkLabel,
}: DocumentSummaryCardProps) {
    return (
        <Sheet title={title}>
            <div className="flex items-start justify-between gap-4">
                <div>
                    <MoneyText minor={outstandingTotal} currency={currency} size="lg" className="text-left" />
                    <p className="mt-1 text-xs text-ink-700">{pluralize(outstandingCount, noun)} outstanding</p>
                </div>

                {overdueCount > 0 && (
                    <div className="shrink-0 rounded-lg bg-amber-50 px-3 py-2 text-right">
                        <MoneyText minor={overdueTotal} currency={currency} size="sm" className="text-clay" />
                        <p className="text-xs text-ink-700">{pluralize(overdueCount, noun)} overdue</p>
                    </div>
                )}
            </div>

            <Link
                href={href}
                className="mt-4 inline-block text-sm text-spark underline decoration-dotted underline-offset-4 transition duration-[120ms] ease-out hover:no-underline motion-reduce:transition-none"
            >
                {linkLabel}
            </Link>
        </Sheet>
    );
}
