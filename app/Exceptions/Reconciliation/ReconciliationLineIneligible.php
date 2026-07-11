<?php

namespace App\Exceptions\Reconciliation;

/**
 * Thrown by ReconciliationService::complete() when a ticked journal_line_id
 * fails any of its three eligibility checks: it doesn't belong to the bank
 * account's linked ledger account, it's dated after the statement date, or
 * it's already reconciled by another live reconciliation (the
 * reconciliation_lines unique index's application-level counterpart).
 */
class ReconciliationLineIneligible extends ReconciliationException {}
