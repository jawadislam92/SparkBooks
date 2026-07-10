<?php

namespace App\Exceptions\Documents;

use DomainException;

/**
 * Base for every domain guard BillPaymentService::void() enforces before it
 * will reverse P7 — currently just "this payment has already been reversed"
 * (payment.status !== 'posted'). Deliberately NOT a LedgerException, mirrors
 * Invoicing\PaymentVoidException. Lives alongside BillPaymentException (not
 * a subtype of it, nor of DocumentVoidException) — voiding a payment is a
 * distinct action from recording one or voiding the bill itself, with its
 * own tiny failure vocabulary, exactly as Invoicing keeps PaymentVoidException
 * a sibling of PaymentException/InvoiceVoidException rather than a subtype of
 * either. Caught as one type by BillController::voidPayment to surface a
 * friendly 422.
 */
abstract class BillPaymentVoidException extends DomainException {}
