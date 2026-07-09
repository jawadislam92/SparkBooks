<?php

namespace App\Exceptions\Invoicing;

/**
 * Thrown by InvoiceService::send() when a draft's computed total is zero
 * (every line is $0 and there is no tax) — there is nothing to post, and an
 * empty/zero AR entry would violate the ledger's "each line one-sided" rule.
 */
class InvoiceHasNoValue extends InvoiceSendException {}
