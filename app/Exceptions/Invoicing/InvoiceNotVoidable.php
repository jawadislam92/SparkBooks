<?php

namespace App\Exceptions\Invoicing;

/**
 * Thrown by InvoiceService::void() when the invoice's current status doesn't
 * allow voiding: either it never left draft (nothing posted yet to reverse)
 * or it has already been voided (InvoiceStatus::canVoid() is false in both
 * cases — the caller distinguishes the message).
 */
class InvoiceNotVoidable extends InvoiceVoidException {}
