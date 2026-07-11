<?php

namespace App\Exceptions\Reconciliation;

/**
 * Thrown by ReconciliationService::complete() when the new statement date is
 * not strictly after the account's most recent COMPLETED reconciliation
 * (spec §7.9's chronological-chain requirement). opening_reconciled_balance
 * is carried from that latest completed reconciliation's own
 * statement_ending_balance, so completing an EARLIER (or same-dated)
 * statement after a later one would silently take the later statement's
 * ending as this one's opening — a corrupt chain. Refusing here keeps the
 * opening-reconciled-balance chain consistent BY CONSTRUCTION, the completion
 * mirror of reopen()'s own newest-first (ReconciliationNotNewest) guard.
 */
class ReconciliationOutOfOrder extends ReconciliationException {}
