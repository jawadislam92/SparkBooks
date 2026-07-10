/**
 * Shared "is this bill overdue" predicate — Bills/Index (row highlight +
 * the Overdue filter chip) and Bills/Show (the header's Overdue tag) both
 * import this rather than each re-deriving it inline. Mirrors the exact
 * rule Invoices/Index has always used (spec §7.14's overdue definition):
 * only a still-payable document (here: received/partially_paid — the AP
 * equivalent of an invoice's sent/partially_paid) whose due date has
 * actually passed reads as overdue — never draft, paid, or void, and never
 * a due date that's merely TODAY (strictly less-than, not less-or-equal).
 *
 * Deliberately extracted into its own tested module (unlike Invoices/
 * Index's own copy, which has stayed private and untested since Phase 3) so
 * this Phase 4 Task 9 addition has direct vitest coverage.
 */

const OVERDUE_ELIGIBLE_STATUSES = ['received', 'partially_paid'];

export interface OverdueCheckBill {
    status: string;
    due_date: string | null;
}

export function isBillOverdue(bill: OverdueCheckBill, today: string): boolean {
    return (
        OVERDUE_ELIGIBLE_STATUSES.includes(bill.status) &&
        bill.due_date !== null &&
        bill.due_date < today
    );
}

/** ISO `YYYY-MM-DD` for the viewer's local today — mirrors the identical helper duplicated across Invoices/Index, Invoices/Show, PaymentModal, and VoidDialog. */
export function todayIsoDate(): string {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}
