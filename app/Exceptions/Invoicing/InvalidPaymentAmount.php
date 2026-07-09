<?php

namespace App\Exceptions\Invoicing;

/**
 * Thrown by InvoicePaymentService::recordPayment() when amount < 1.
 * Defense-in-depth: StorePaymentRequest already enforces this at the HTTP
 * layer (`min:1`); this re-check protects any other caller of the service.
 */
class InvalidPaymentAmount extends PaymentException {}
