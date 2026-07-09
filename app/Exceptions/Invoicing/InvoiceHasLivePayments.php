<?php

namespace App\Exceptions\Invoicing;

/**
 * Thrown by InvoiceService::void() when the invoice still has one or more
 * non-void payments recorded against it. Spec §6: payments must be voided
 * before the invoice itself can be voided — this is a conscious, per-payment
 * user action (InvoicePaymentService::voidPayment), never an automatic
 * cascade triggered by voiding the invoice.
 */
class InvoiceHasLivePayments extends InvoiceVoidException {}
