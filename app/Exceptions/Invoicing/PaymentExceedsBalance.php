<?php

namespace App\Exceptions\Invoicing;

/**
 * Thrown by InvoicePaymentService::recordPayment() when the requested amount
 * is greater than the invoice's current outstanding balance — the
 * overpayment guard, checked on a lockForUpdate() read of the invoice row
 * (spec §9) so two concurrent payments can't both pass it.
 */
class PaymentExceedsBalance extends PaymentException {}
