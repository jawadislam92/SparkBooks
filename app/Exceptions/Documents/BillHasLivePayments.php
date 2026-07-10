<?php

namespace App\Exceptions\Documents;

/**
 * Thrown by BillService::void() when the bill still has one or more
 * non-void payments recorded against it. Spec §6 (mirrors Invoicing\
 * InvoiceHasLivePayments): payments must be voided before the bill itself
 * can be voided — this is a conscious, per-payment user action
 * (BillPaymentService::void), never an automatic cascade triggered by
 * voiding the bill.
 */
class BillHasLivePayments extends DocumentVoidException {}
