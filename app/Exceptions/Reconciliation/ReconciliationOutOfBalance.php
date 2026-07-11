<?php

namespace App\Exceptions\Reconciliation;

/**
 * Thrown by ReconciliationService::complete() when statement_ending_balance
 * − (opening_reconciled_balance + Σ ticked lines' signed amounts) is anything
 * other than exactly 0 (spec §7.9: "Finish only enabled at difference 0").
 * Nothing is stored when this is thrown — the guard runs before the
 * reconciliation row or any reconciliation_lines row is created.
 */
class ReconciliationOutOfBalance extends ReconciliationException {}
