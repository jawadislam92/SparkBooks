import { useEffect, useRef, useState } from 'react';
import { formatMoney } from '@/lib/money';

interface BalanceBeamProps {
    /** Sum of debit lines, in integer minor units. */
    debitTotal: number;
    /** Sum of credit lines, in integer minor units. */
    creditTotal: number;
    currency: string;
    className?: string;
}

function clamp(value: number, min: number, max: number): number {
    return Math.min(Math.max(value, min), max);
}

function prefersReducedMotion(): boolean {
    return (
        typeof window !== 'undefined' &&
        typeof window.matchMedia === 'function' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}

/**
 * The signature 3px gradient bar: tilts up to ±3° toward whichever side of a
 * debit/credit pair is heavier, and settles level with a brief glow when
 * balanced and non-zero. Pure CSS-transform, no animation library. Lives in
 * the manual-JE editor and the Trial Balance footer — never decorative.
 */
export default function BalanceBeam({
    debitTotal,
    creditTotal,
    currency,
    className = '',
}: BalanceBeamProps) {
    const diff = creditTotal - debitTotal;
    const denom = Math.max(debitTotal, creditTotal, 1);
    const tilt = clamp(diff / denom, -1, 1) * 3;
    const isZero = debitTotal === 0 && creditTotal === 0;
    const isBalanced = diff === 0;
    const isBalancedNonZero = isBalanced && !isZero;

    const wasBalancedNonZeroRef = useRef(false);
    const [isPulsing, setIsPulsing] = useState(false);

    useEffect(() => {
        const justBecameBalanced =
            isBalancedNonZero && !wasBalancedNonZeroRef.current;
        wasBalancedNonZeroRef.current = isBalancedNonZero;

        if (!justBecameBalanced || prefersReducedMotion()) {
            return undefined;
        }

        setIsPulsing(true);
        const timer = window.setTimeout(() => setIsPulsing(false), 240);

        return () => window.clearTimeout(timer);
    }, [isBalancedNonZero]);

    let announcement: string;

    if (isBalanced) {
        announcement = 'Balanced';
    } else {
        const amount = formatMoney(Math.abs(diff), currency);
        const heavierSide = debitTotal > creditTotal ? 'debits' : 'credits';
        announcement = `Out by ${amount}, ${heavierSide} heavier`;
    }

    return (
        <div className={`flex flex-col items-center gap-2 ${className}`}>
            <div
                className={
                    `h-[3px] w-full origin-center rounded-full bg-gradient-to-r from-spark to-lagoon transition-transform duration-[240ms] ease-[cubic-bezier(0.34,1.56,0.64,1)] motion-reduce:transition-none motion-reduce:duration-0 ` +
                    (isZero ? 'opacity-30' : 'opacity-100') +
                    (isPulsing
                        ? ' shadow-[0_0_12px_3px_rgba(15,166,120,0.35)]'
                        : '')
                }
                style={{ transform: `rotate(${tilt}deg)` }}
            />
            <p className="font-sans text-sm text-ink-700" aria-live="polite">
                {announcement}
            </p>
        </div>
    );
}
