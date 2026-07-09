<?php

namespace App\Exceptions\Invoicing;

use DomainException;

/**
 * Base for every domain guard InvoiceService::send() enforces before it will
 * post P1 — invoice not a draft, no lines, contact not eligible. Deliberately
 * NOT a LedgerException: these are invoice-specific business rules checked
 * BEFORE LedgerService is ever called, not ledger posting-time violations
 * (locked period, unbalanced entry, etc. still surface as LedgerException).
 * Caught as one type by InvoiceController::send to surface a friendly 422.
 */
abstract class InvoiceSendException extends DomainException {}
