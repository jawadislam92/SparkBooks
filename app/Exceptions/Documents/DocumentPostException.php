<?php

namespace App\Exceptions\Documents;

use DomainException;

/**
 * Base for every domain guard ExpenseService::record()/BillService::receive()
 * enforce before they will post P4/P5/P6/P6t — not a draft, no lines, no
 * value, an ineligible bill contact, a missing bank account/due date.
 * Deliberately NOT a LedgerException (mirrors Invoicing\InvoiceSendException,
 * Phase 3 T3): these are expense/bill-specific business rules checked BEFORE
 * LedgerService is ever called, not ledger posting-time violations (locked
 * period, unbalanced entry, ...) which still surface as LedgerException.
 *
 * Shared by BOTH Expense and Bill (the AP-side "record"/"receive" mirror
 * pair, Phase 4 T3/T4 — identical shape, siblings by design) rather than
 * split into an Expense-only and a Bill-only hierarchy: the not-draft/
 * no-lines/no-value rules are IDENTICAL business rules on both document
 * types, so one class each avoids the drift a duplicated hierarchy would
 * invite. Only the document-specific guards (bank account for Expense;
 * vendor contact/due date for Bill) get their own subclass. Caught as one
 * type by ExpenseController::record/BillController::receive to surface a
 * friendly 422.
 */
abstract class DocumentPostException extends DomainException {}
