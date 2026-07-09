export const INVOICE_STATUS_LABELS: Record<string, string> = {
    draft: 'Draft',
    sent: 'Sent',
    partially_paid: 'Partially paid',
    paid: 'Paid',
    void: 'Void',
};

// This app's palette only defines a 50-shade (or DEFAULT-with-opacity) for
// each accent color, not a full scale, so every status chip is built from
// one of these exact token pairs — draft=neutral paper, sent=lagoon tint,
// partially_paid=amber-50, paid=spark-50, void=clay-50 (design brief §Show).
const INVOICE_STATUS_CHIP_CLASSES: Record<string, string> = {
    draft: 'bg-paper text-ink-700',
    sent: 'bg-lagoon/10 text-lagoon',
    partially_paid: 'bg-amber-50 text-ink',
    paid: 'bg-spark-50 text-ink',
    void: 'bg-clay-50 text-clay',
};

/**
 * The invoice status chip — shared by Invoices/Index and Invoices/Show so
 * the two screens never drift on color/label.
 */
export default function StatusChip({ status }: { status: string }) {
    return (
        <span
            className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${
                INVOICE_STATUS_CHIP_CLASSES[status] ?? 'bg-paper text-ink-700'
            }`}
        >
            {INVOICE_STATUS_LABELS[status] ?? status}
        </span>
    );
}
