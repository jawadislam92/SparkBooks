<?php

namespace App\Exceptions\Invoicing;

/** Thrown by InvoicePaymentService::recordPayment() when the invoice's status isn't sent or partially_paid. */
class InvoiceNotPayable extends PaymentException {}
