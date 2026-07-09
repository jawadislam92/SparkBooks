<?php

namespace App\Exceptions\Invoicing;

/** Thrown by InvoiceService::send() when the invoice's contact isn't a customer (or both). */
class InvoiceContactNotEligible extends InvoiceSendException {}
