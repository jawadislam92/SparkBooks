import { HTMLAttributes } from 'react';
import { formatMoney } from '@/lib/money';

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
    return (
        <span
            {...props}
            className={
                `block text-right font-mono tabular-nums text-ink ${SIZE_CLASSES[size]} ` +
                className
            }
        >
            {formatMoney(minor, currency)}
        </span>
    );
}
