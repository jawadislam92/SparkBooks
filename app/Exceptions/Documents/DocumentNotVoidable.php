<?php

namespace App\Exceptions\Documents;

/**
 * Thrown by ExpenseService::void()/BillService::void() when the document's
 * current status doesn't allow voiding: either it never left draft (nothing
 * posted yet to reverse) or it has already been voided (ExpenseStatus::
 * canVoid()/BillStatus::canVoid() is false in both cases — the caller
 * distinguishes the message). Mirrors Invoicing\InvoiceNotVoidable.
 */
class DocumentNotVoidable extends DocumentVoidException {}
