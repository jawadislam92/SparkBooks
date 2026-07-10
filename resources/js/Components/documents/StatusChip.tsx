export const EXPENSE_STATUS_LABELS: Record<string, string> = {
    draft: 'Draft',
    recorded: 'Recorded',
    void: 'Void',
};

export const BILL_STATUS_LABELS: Record<string, string> = {
    draft: 'Draft',
    received: 'Received',
    partially_paid: 'Partially paid',
    paid: 'Paid',
    void: 'Void',
};

// Same token pairs as Components/invoices/StatusChip — draft=neutral paper,
// a posted/in-progress state=lagoon tint, a completed state=spark-50,
// void=clay-50 — so a status chip reads consistently everywhere in the app.
const STATUS_CHIP_CLASSES: Record<string, string> = {
    draft: 'bg-paper text-ink-700',
    recorded: 'bg-spark-50 text-ink',
    received: 'bg-lagoon/10 text-lagoon',
    partially_paid: 'bg-amber-50 text-ink',
    paid: 'bg-spark-50 text-ink',
    void: 'bg-clay-50 text-clay',
};

interface StatusChipProps {
    status: string;
    labels: Record<string, string>;
}

/**
 * The expense/bill status chip — shared by Expenses/Index and Bills/Index
 * (each passing its own status vocabulary) so the two document types never
 * drift on color/shape, mirroring Components/invoices/StatusChip.
 */
export default function StatusChip({ status, labels }: StatusChipProps) {
    return (
        <span
            className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${
                STATUS_CHIP_CLASSES[status] ?? 'bg-paper text-ink-700'
            }`}
        >
            {labels[status] ?? status}
        </span>
    );
}
