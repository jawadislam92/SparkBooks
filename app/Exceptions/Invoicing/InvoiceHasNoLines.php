<?php

namespace App\Exceptions\Invoicing;

/** Thrown by InvoiceService::send() when the draft has no lines to post. */
class InvoiceHasNoLines extends InvoiceSendException {}
