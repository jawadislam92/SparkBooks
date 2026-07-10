<?php

namespace App\Exceptions\Documents;

/**
 * Thrown by BillPaymentService::record() when the requested amount is
 * greater than the bill's current outstanding balance — the overpayment
 * guard, checked on a lockForUpdate() read of the bill row (spec §9) so two
 * concurrent payments can't both pass it. Mirrors Invoicing\PaymentExceedsBalance.
 */
class BillPaymentExceedsBalance extends BillPaymentException {}
