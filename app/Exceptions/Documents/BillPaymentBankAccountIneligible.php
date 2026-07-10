<?php

namespace App\Exceptions\Documents;

/**
 * Thrown by BillPaymentService::record() when the chosen bank account
 * doesn't belong to this tenant or is archived. Mirrors
 * Invoicing\PaymentBankAccountIneligible.
 */
class BillPaymentBankAccountIneligible extends BillPaymentException {}
