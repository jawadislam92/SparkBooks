<?php

namespace App\Exceptions\Invoicing;

/**
 * Thrown by InvoiceService::send() when the invoice's currency_code differs
 * from the company's base currency and the supplied fx_rate is not a valid
 * conversion rate — missing/empty, non-numeric, <= 0, or with more than 8
 * decimal places (DECIMAL(16,8)). "Valid fx_rate shape" is defined in exactly
 * one place: FxConverter's own guard, which resolveFxRate probes and re-throws
 * as this domain exception so the controller maps it to a friendly 422 on the
 * fx_rate field instead of letting FxConverter's raw InvalidArgumentException
 * escape as a 500. A domestic invoice never reaches this guard: its fx_rate is
 * always fixed at 1.0 internally, whatever the request supplies.
 */
class InvoiceFxRateInvalid extends InvoiceSendException {}
