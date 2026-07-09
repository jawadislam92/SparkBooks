import { HTMLAttributes, ReactNode } from 'react';

interface StatTileProps extends HTMLAttributes<HTMLDivElement> {
    label: string;
    children: ReactNode;
}

/**
 * A small headline-number card — the Dashboard's cash position/customers/
 * vendors row (Phase 2's "light" wiring; real widgets are Phase 5). Pass a
 * <MoneyText> or plain figure as the child; this only supplies the card
 * chrome and label.
 */
export default function StatTile({
    label,
    className = '',
    children,
    ...props
}: StatTileProps) {
    return (
        <div
            {...props}
            className={`rounded-sheet bg-paper-raised p-5 shadow-sheet ` + className}
        >
            <p className="text-xs font-semibold uppercase tracking-wide text-ink-700">
                {label}
            </p>
            <div className="mt-2">{children}</div>
        </div>
    );
}
