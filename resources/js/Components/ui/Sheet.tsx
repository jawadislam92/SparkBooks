import { HTMLAttributes, ReactNode } from 'react';

interface SheetProps extends HTMLAttributes<HTMLDivElement> {
    title?: string;
    actions?: ReactNode;
}

/**
 * The white rounded card every screen is built from — per the design
 * system's Surfaces spec (0.875rem radius, the two-layer resting shadow).
 */
export default function Sheet({
    title,
    actions,
    className = '',
    children,
    ...props
}: SheetProps) {
    return (
        <div
            {...props}
            className={`rounded-sheet bg-paper-raised shadow-sheet ` + className}
        >
            {(title || actions) && (
                <div className="flex items-center justify-between gap-4 border-b border-rule px-6 py-4">
                    {title && (
                        <h2 className="font-display text-lg font-semibold text-ink">
                            {title}
                        </h2>
                    )}
                    {actions && (
                        <div className="flex items-center gap-2">{actions}</div>
                    )}
                </div>
            )}
            <div className="p-6">{children}</div>
        </div>
    );
}
