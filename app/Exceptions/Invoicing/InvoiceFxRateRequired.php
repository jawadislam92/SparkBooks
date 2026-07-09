<?php

namespace App\Exceptions\Invoicing;

/**
 * Thrown by InvoiceService::send() when the invoice's currency_code differs
 * from the company's base currency and no valid (present, positive, correctly
 * shaped) fx_rate was supplied with the send request — posting rule P1fx
 * (spec §17.1) cannot compute base mirrors without one. A domestic invoice
 * never reaches this guard: its fx_rate is always fixed at 1.0 internally.
 */
class InvoiceFxRateRequired extends InvoiceSendException {}
