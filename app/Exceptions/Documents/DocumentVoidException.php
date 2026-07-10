<?php

namespace App\Exceptions\Documents;

use DomainException;

/**
 * Base for every domain guard ExpenseService::void()/BillService::void()
 * enforce before they will reverse P4/P5/P6/P6t (posting rule P9, spec §6) —
 * the document's status doesn't allow voiding (never posted, or already
 * void), or (Bill only) it still has one or more live (non-void) payments
 * that must be reversed first via BillPaymentService::void, or (Bill only)
 * it's an is_opening bill that must be corrected via Opening Balances
 * instead. Deliberately NOT a LedgerException: these are document-specific
 * business rules checked BEFORE LedgerService is ever called, not ledger
 * posting-time violations (locked period, unbalanced entry, etc. still
 * surface as LedgerException). Mirrors Invoicing\InvoiceVoidException.
 *
 * Shared by BOTH Expense and Bill (mirrors DocumentPostException's own
 * sharing rationale, Phase 4 T3/T4) — the not-voidable/already-void rule is
 * an IDENTICAL business rule on both document types. Only the
 * Bill-specific guards (live payments, is_opening) get their own subclass;
 * Expense has neither. Caught as one type by
 * ExpenseController::void/BillController::void to surface a friendly 422.
 */
abstract class DocumentVoidException extends DomainException {}
