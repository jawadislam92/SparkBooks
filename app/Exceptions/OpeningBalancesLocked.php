<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown by OpeningBalanceService::apply() when a re-run is attempted after
 * a real (non-opening) transaction has already posted for the company.
 *
 * Deliberately NOT a LedgerException: this is an opening-balances-specific
 * business rule (spec §7.1 — "re-running setup is allowed until the first
 * real transaction"), not a ledger-wide invariant. LedgerService has no
 * concept of "opening balance runs" and must not be taught one; the rule
 * lives in, and is thrown from, OpeningBalanceService, and is caught
 * separately by OpeningBalanceController::store to surface a friendly error
 * next to the books-start-date field. Mirrors IneligibleCategoryAccount's
 * reasoning for OtherReceiptService.
 */
class OpeningBalancesLocked extends DomainException {}
