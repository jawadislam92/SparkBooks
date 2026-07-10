<?php

namespace App\Exceptions\Documents;

/**
 * Thrown when a draft's computed total is zero (every line is $0 and there
 * is no tax) — there is nothing to post, and a zero-value ledger line would
 * violate LedgerService's "each line one-sided" rule. Mirrors
 * Invoicing\InvoiceHasNoValue (Phase 3 T3).
 */
class DocumentHasNoValue extends DocumentPostException {}
