<?php

namespace App\Exceptions\Invoicing;

/**
 * Thrown by InvoiceService::void() when the invoice is_opening — it was
 * created by OpeningBalanceService::createOpeningInvoice() (Phase 3 Task 6),
 * not by send(), and carries no P1 entry of its own to reverse (its AR was
 * posted once by the opening_balance_run entry, keyed by contact_id — see
 * that method's own docblock). Correcting it means re-running opening
 * balances (OpeningBalanceService::apply(), which voids the prior run's
 * is_opening invoices directly — see voidPriorRun(), which never calls this
 * service and so is unaffected by this guard), not voiding the invoice
 * directly.
 *
 * Phase 3 carry-in, closed by Phase 4 Task 6 (folded in alongside the new,
 * mirror-image Documents\BillIsOpening guard for bills): PROJECT_MAP §5
 * already documented void() as "404s on an is_opening invoice", but no such
 * guard actually existed in code before this change — voiding an is_opening
 * invoice directly would have posted a phantom P9 reversal against an
 * invoice with no P1 entry to reverse (JournalEntry::firstOrFail() would
 * have 404'd, not this friendly domain error). This is a friendlier 422, not
 * a 404 — the invoice genuinely exists, it's just not correctable this way.
 */
class InvoiceIsOpening extends InvoiceVoidException {}
