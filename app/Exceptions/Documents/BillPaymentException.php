<?php

namespace App\Exceptions\Documents;

use DomainException;

/**
 * Base for every domain guard BillPaymentService::record() enforces before
 * it will post P7 — bill not payable, invalid amount, overpayment,
 * ineligible bank account. Deliberately NOT a LedgerException: these are
 * payment-specific business rules checked BEFORE LedgerService is ever
 * called, not ledger posting-time violations (locked period, unbalanced
 * entry, etc. still surface as LedgerException). Lives in the Documents
 * namespace (not Invoicing) because it's the AP-side sibling of
 * DocumentPostException — both guard Bill (and, for DocumentPostException,
 * Expense) document actions — but is its own family, not a DocumentPostException
 * subtype, since paying a bill is a distinct action from receiving one and
 * has its own failure vocabulary (overpayment, ineligible bank account) that
 * receive() never needs. Mirrors Invoicing\PaymentException exactly, one
 * document type over. Caught as one type by BillController::recordPayment to
 * surface a friendly 422.
 */
abstract class BillPaymentException extends DomainException {}
