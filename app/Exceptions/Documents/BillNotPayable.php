<?php

namespace App\Exceptions\Documents;

/**
 * Thrown by BillPaymentService::record() when the bill's current status
 * doesn't allow a payment — draft (never received), paid (nothing left
 * owing), or void. Mirrors Invoicing\InvoiceNotPayable.
 */
class BillNotPayable extends BillPaymentException {}
