<?php

namespace App\Exceptions\Documents;

/**
 * Thrown by BillPaymentService::record() when the requested amount isn't
 * positive. Defense-in-depth — StoreBillPaymentRequest already enforces
 * `min:1` at the HTTP layer — but this service is the actual money-moving
 * boundary, so it re-checks rather than trusting the caller. Mirrors
 * Invoicing\InvalidPaymentAmount.
 */
class InvalidBillPaymentAmount extends BillPaymentException {}
