import { HTMLAttributes } from 'react';
import { formatMoney } from '@/lib/money';
import { hasTextColorClass } from '@/lib/textColor';

type MoneyTextSize = 'sm' | 'md' | 'lg';

interface MoneyTextProps extends HTMLAttributes<HTMLSpanElement> {
    /** Integer minor units (e.g. cents, paisa) — never a float major amount. */
    minor: number;
    currency: string;
    size?: MoneyTextSize;
}

const SIZE_CLASSES: Record<MoneyTextSize, string> = {
    sm: 'text-sm',
    md: 'text-base',
    lg: 'text-2xl',
};

/** Every money figure in the app renders through this — mono, tabular, right-aligned. */
export default function MoneyText({
    minor,
    currency,
    size = 'md',
    className = '',
    ...props
}: MoneyTextProps) {
    // Emit the default `text-ink` ONLY when the caller didn't pass a text
    // color of their own. Both utilities have equal specificity, so if we
    // always emitted `text-ink` the winner would be decided by Tailwind's
    // stylesheet ordering (in the current build `.text-ink` sorts after
    // `.text-clay`, so a `text-clay` override would silently lose). Omitting
    // it here lets an explicit override win deterministically.
    const defaultColor = hasTextColorClass(className) ? '' : 'text-ink';
    const classes = [
        'block text-right font-mono tabular-nums',
        defaultColor,
        SIZE_CLASSES[size],
        className,
    ]
        .filter(Boolean)
        .join(' ');

    return (
        <span {...props} className={classes}>
            {formatMoney(minor, currency)}
        </span>
    );
}
