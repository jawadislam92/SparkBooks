<?php

namespace App\Exceptions\Documents;

/** Thrown by BillPaymentService::void() when the payment's status is already 'void'. Mirrors Invoicing\PaymentAlreadyVoided. */
class BillPaymentAlreadyVoided extends BillPaymentVoidException {}
