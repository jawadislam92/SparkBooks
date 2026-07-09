<?php

namespace App\Exceptions\Invoicing;

use DomainException;

/**
 * Base for every domain guard InvoicePaymentService::voidPayment() enforces
 * before it will reverse P2/P3 — currently just "this payment has already
 * been reversed" (payment.status !== 'posted'). Deliberately NOT a
 * LedgerException, mirrors InvoiceVoidException/PaymentException. Caught as
 * one type by InvoiceController::voidPayment to surface a friendly 422.
 */
abstract class PaymentVoidException extends DomainException {}
