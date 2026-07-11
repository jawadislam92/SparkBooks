<?php

namespace App\Exceptions\Reconciliation;

use DomainException;

/**
 * Base for every domain guard ReconciliationService enforces — out-of-balance
 * completion, an ineligible ticked line, reopening out of newest-first order.
 * Deliberately NOT a LedgerException: reconciliation never calls
 * LedgerService at all (spec §7.9 — it records which lines cleared the
 * statement, it never posts). Mirrors Invoicing\PaymentException /
 * Documents\BillPaymentException. Caught as one type by
 * ReconciliationController to surface a friendly 422.
 */
abstract class ReconciliationException extends DomainException {}
