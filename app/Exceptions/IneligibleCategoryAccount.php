<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown by OtherReceiptService when the chosen category (credit-side)
 * account is not a valid destination for money-in that isn't an invoice: a
 * control account (AR/AP), a bank/cash account (that would be a disguised
 * transfer), or an archived account.
 *
 * Deliberately NOT a LedgerException: LedgerService is the frozen ledger
 * gate and must keep accepting bank/cash lines (they are legitimate for
 * transfers). This eligibility rule is an OtherReceipt-specific business
 * rule, so it lives in — and is thrown from — OtherReceiptService, and is
 * caught separately by OtherReceiptController::store to surface a friendly
 * validation error on the category_account_id field.
 */
class IneligibleCategoryAccount extends DomainException {}
