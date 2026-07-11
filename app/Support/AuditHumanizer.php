<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Turns a raw `activity_log` row's `action` code (and its subject
 * reference) into human-readable strings. Extracted from
 * DashboardController's own recent-activity widget (Phase 5 Task 7) so the
 * Audit log viewer (Phase 6 Task 2, spec §7.13) can share the EXACT same
 * humanization instead of re-deriving it — the two surfaces can never drift
 * apart on what a given action code means.
 *
 * This class only ever turns already-fetched ActivityLog attributes into
 * display strings — it does no logging itself (that stays AuditLogger's
 * job) and never touches the row's `before`/`after` diff columns.
 */
class AuditHumanizer
{
    /**
     * 'invoice.draft_created' -> "Invoice draft created";
     * 'fx_accounts.backfilled' -> "Fx accounts backfilled".
     */
    public static function action(string $action): string
    {
        [$subject, $verb] = array_pad(explode('.', $action, 2), 2, '');

        $words = trim(str_replace('_', ' ', $subject).' '.str_replace('_', ' ', $verb));

        return Str::ucfirst($words);
    }

    /**
     * subject_type is the morph class (no morph map configured — see
     * AppServiceProvider), e.g. "App\Models\BillPayment" -> "Bill Payment #7".
     * Null when the action has no subject (e.g. fx_accounts.backfilled).
     */
    public static function subject(?string $subjectType, ?int $subjectId): ?string
    {
        if ($subjectType === null) {
            return null;
        }

        $label = Str::headline(class_basename($subjectType));

        return $subjectId !== null ? "{$label} #{$subjectId}" : $label;
    }

    /**
     * The action's "module" — everything before the first dot (e.g.
     * 'invoice.sent' -> 'invoice', 'bill_payment.voided' -> 'bill_payment').
     * Used by the Audit log viewer to both derive its module filter options
     * and to narrow the list by module (§7.13).
     */
    public static function module(string $action): string
    {
        return Str::before($action, '.');
    }
}
