<?php

namespace App\Exceptions\Invoicing;

/** Thrown by InvoiceService::send() when the invoice has already left draft status. */
class InvoiceNotDraft extends InvoiceSendException {}
