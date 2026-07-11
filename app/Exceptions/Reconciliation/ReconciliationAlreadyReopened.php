<?php

namespace App\Exceptions\Reconciliation;

/**
 * Thrown by ReconciliationService::reopen() when the target reconciliation's
 * status is already 'reopened' — a second reopen attempt (a different
 * idempotency key from the first) has nothing left to unlock. Distinct from
 * ReconciliationNotNewest, which fires when a NEWER, still-completed
 * reconciliation exists; this one fires when the target itself was already
 * actioned.
 */
class ReconciliationAlreadyReopened extends ReconciliationException {}
