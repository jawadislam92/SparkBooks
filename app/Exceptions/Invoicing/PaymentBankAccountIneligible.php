<?php

namespace App\Exceptions\Invoicing;

/**
 * Thrown by InvoicePaymentService::recordPayment() when the bank account is
 * foreign to the tenant or archived. Defense-in-depth: StorePaymentRequest
 * already enforces this at the HTTP layer; this re-check protects the actual
 * money-moving boundary against any other caller of the service.
 */
class PaymentBankAccountIneligible extends PaymentException {}
