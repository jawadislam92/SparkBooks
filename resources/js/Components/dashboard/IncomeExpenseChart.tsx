import {
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    Legend,
    LinearScale,
    Tooltip,
    type ChartOptions,
} from 'chart.js';
import { useEffect, useRef } from 'react';
import { formatMoney } from '@/lib/money';
import {
    buildIncomeExpenseChartData,
    type IncomeExpenseChartPayload,
} from '@/lib/incomeExpenseChartData';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip, Legend);

// Design-token hex values (tailwind.config.js) — Chart.js draws to a
// <canvas>, which can't resolve Tailwind classes/CSS custom properties, so
// these literal values are the only way to feed it the app's own tokens.
// Not new colors: copied verbatim from theme.extend.colors.
const INK_700 = '#24505E';
const RULE = '#E4EEE6';

interface IncomeExpenseChartProps {
    data: IncomeExpenseChartPayload;
    currency: string;
}

/**
 * The Dashboard's income-vs-expense bar chart (spec §7.12) — the app's only
 * Chart.js surface. Small and non-distracting by design: no chart title (the
 * card header carries that), a thin legend, gridlines in the `rule` token,
 * axis/tooltip text in `ink-700`. Animation duration matches the app's
 * 120ms motion standard and is skipped entirely under
 * `prefers-reduced-motion`, mirroring every other transition in this
 * codebase (see e.g. Pages/Accounts/Index.tsx's `motion-reduce:transition-none`).
 */
export default function IncomeExpenseChart({ data, currency }: IncomeExpenseChartProps) {
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const chartRef = useRef<Chart | null>(null);

    useEffect(() => {
        const canvas = canvasRef.current;
        if (!canvas) {
            return;
        }

        const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        const options: ChartOptions<'bar'> = {
            responsive: true,
            maintainAspectRatio: false,
            animation: prefersReducedMotion ? false : { duration: 120 },
            plugins: {
                legend: {
                    position: 'top',
                    align: 'end',
                    labels: {
                        color: INK_700,
                        boxWidth: 10,
                        boxHeight: 10,
                        font: { size: 11 },
                    },
                },
                tooltip: {
                    callbacks: {
                        label: (context) =>
                            `${context.dataset.label}: ${formatMoney(context.parsed.y ?? 0, currency)}`,
                    },
                },
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { color: INK_700, font: { size: 11 } },
                },
                y: {
                    beginAtZero: true,
                    grid: { color: RULE },
                    ticks: {
                        color: INK_700,
                        font: { size: 11 },
                        maxTicksLimit: 5,
                        callback: (value) => formatMoney(Number(value), currency),
                    },
                },
            },
        };

        if (!chartRef.current) {
            chartRef.current = new Chart(canvas, {
                type: 'bar',
                data: buildIncomeExpenseChartData(data),
                options,
            });

            return;
        }

        chartRef.current.data = buildIncomeExpenseChartData(data);
        chartRef.current.options = options;
        chartRef.current.update();
    }, [data, currency]);

    useEffect(() => {
        return () => {
            chartRef.current?.destroy();
            chartRef.current = null;
        };
    }, []);

    return (
        <div className="h-56 w-full">
            <canvas ref={canvasRef} role="img" aria-label="Income vs expense, last six months" />
        </div>
    );
}
