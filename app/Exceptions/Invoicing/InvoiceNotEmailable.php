<?php

namespace App\Exceptions\Invoicing;

/**
 * Thrown by InvoiceService::sendEmail()/resendEmail() when the invoice's
 * current status isn't posted (InvoiceStatus::isPosted() is false) — a
 * draft has never been sent, and a voided invoice's email is a dead action.
 */
class InvoiceNotEmailable extends InvoiceEmailException {}
