import MoneyText from '@/Components/ui/MoneyText';
import Sheet from '@/Components/ui/Sheet';
import { Link } from '@inertiajs/react';

interface PlSnapshotCardProps {
    periodLabel: string;
    from: string;
    to: string;
    income: number;
    expenses: number;
    netProfit: number;
    currency: string;
}

/** Positive amounts read as spark (healthy), negative as clay (a loss) — same convention Reports/ProfitAndLoss.tsx uses for its own Net Profit line. */
function toneClass(amount: number): string {
    return amount < 0 ? 'text-clay' : 'text-spark';
}

function formatDate(value: string): string {
    return new Date(`${value}T00:00:00`).toLocaleDateString('en-PK', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

/** The Dashboard's P&L snapshot widget (spec §7.12) — current fiscal-year-to-date net profit, drilling into the full report. */
export default function PlSnapshotCard({
    periodLabel,
    from,
    to,
    income,
    expenses,
    netProfit,
    currency,
}: PlSnapshotCardProps) {
    return (
        <Sheet
            title="Profit & loss"
            actions={
                <Link
                    href={route('reports.profit-and-loss')}
                    className="text-sm text-spark underline decoration-dotted underline-offset-4 transition duration-[120ms] ease-out hover:no-underline motion-reduce:transition-none"
                >
                    Full report
                </Link>
            }
        >
            <p className="text-xs text-ink-700">
                {periodLabel} · {formatDate(from)} – {formatDate(to)}
            </p>

            <div className="mt-4 grid grid-cols-3 gap-3">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wide text-ink-700">Income</p>
                    <MoneyText minor={income} currency={currency} size="md" className="text-left" />
                </div>
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wide text-ink-700">Expenses</p>
                    <MoneyText minor={expenses} currency={currency} size="md" className="text-left" />
                </div>
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wide text-ink-700">Net profit</p>
                    <MoneyText
                        minor={netProfit}
                        currency={currency}
                        size="md"
                        className={`text-left font-semibold ${toneClass(netProfit)}`}
                    />
                </div>
            </div>
        </Sheet>
    );
}
