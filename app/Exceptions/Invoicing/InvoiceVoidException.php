<?php

namespace App\Exceptions\Invoicing;

use DomainException;

/**
 * Base for every domain guard InvoiceService::void() enforces before it will
 * reverse P1 (posting rule P9, spec §6) — the invoice's status doesn't allow
 * voiding, or it still has one or more live (non-void) payments that must be
 * reversed first via InvoicePaymentService::voidPayment. Deliberately NOT a
 * LedgerException: these are invoice-specific business rules checked BEFORE
 * LedgerService is ever called, not ledger posting-time violations (locked
 * period, unbalanced entry, etc. still surface as LedgerException). Mirrors
 * InvoiceSendException/PaymentException. Caught as one type by
 * InvoiceController::void to surface a friendly 422.
 */
abstract class InvoiceVoidException extends DomainException {}
