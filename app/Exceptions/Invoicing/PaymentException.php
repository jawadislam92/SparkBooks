<?php

namespace App\Exceptions\Invoicing;

use DomainException;

/**
 * Base for every domain guard InvoicePaymentService::recordPayment() enforces
 * before it will post P2/P3 — invoice not payable, invalid amount,
 * overpayment, ineligible bank account. Deliberately NOT a LedgerException:
 * these are payment-specific business rules checked BEFORE LedgerService is
 * ever called, not ledger posting-time violations (locked period, unbalanced
 * entry, etc. still surface as LedgerException). Mirrors InvoiceSendException.
 * Caught as one type by InvoiceController::recordPayment to surface a
 * friendly 422.
 */
abstract class PaymentException extends DomainException {}
