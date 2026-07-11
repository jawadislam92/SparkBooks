<?php

namespace App\Exceptions\Reconciliation;

/**
 * Thrown by ReconciliationService::reopen() when a NEWER completed
 * reconciliation exists for the same bank account (spec §7.9: "Reopening
 * must proceed newest-first so the opening-reconciled-balance chain stays
 * consistent") — reopening this one first would leave the newer
 * reconciliation's own opening_reconciled_balance pointing at a statement
 * balance that's no longer settled.
 */
class ReconciliationNotNewest extends ReconciliationException {}
