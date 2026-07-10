<?php

namespace App\Exceptions\Documents;

/**
 * Thrown by BillService::void() when the bill is_opening — it will be
 * created by OpeningBalanceService's payable (AP) side (Phase 4 Task 7, not
 * yet built), not by receive(), and will carry no P6/P6t entry of its own to
 * reverse (its AP was posted once by the opening_balance_run entry, keyed by
 * contact_id — mirrors is_opening Invoice's own AR treatment,
 * OpeningBalanceService::createOpeningInvoice). Correcting it means
 * re-running opening balances, not voiding the bill directly. This guard is
 * built NOW (Task 6), ahead of Task 7 creating any is_opening bill, because
 * Task 7 depends on it already being in place. Mirrors
 * Invoicing\InvoiceIsOpening (folded into InvoiceService::void this same
 * task as a Phase 3 carry-in fix).
 */
class BillIsOpening extends DocumentVoidException {}
