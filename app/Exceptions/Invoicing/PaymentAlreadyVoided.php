<?php

namespace App\Exceptions\Invoicing;

/** Thrown by InvoicePaymentService::voidPayment() when the payment's status is already 'void'. */
class PaymentAlreadyVoided extends PaymentVoidException {}
