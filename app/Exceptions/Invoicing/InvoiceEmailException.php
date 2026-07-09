<?php

namespace App\Exceptions\Invoicing;

use DomainException;

/**
 * Base for every domain guard InvoiceService::sendEmail()/resendEmail()
 * enforces BEFORE queuing SendInvoiceEmail — currently just "the invoice
 * must be posted" (a draft has nothing to send; a void invoice is dead).
 * Deliberately separate from InvoiceSendException/InvoiceVoidException even
 * though the shape is identical: emailing is its own decoupled action (spec
 * §17.6), not a step of send()/void()'s own guard chains. Caught as one type
 * by InvoiceController::email()/resendEmail() to surface a friendly 422.
 */
abstract class InvoiceEmailException extends DomainException {}
