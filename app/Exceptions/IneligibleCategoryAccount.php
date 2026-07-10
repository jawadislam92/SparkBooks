<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown when a chosen "category" account is not a valid destination for the
 * money movement being recorded. Two independent rules share this one
 * exception:
 *
 * - OtherReceiptService (Phase 2 T7, money-IN): a control account (AR/AP), a
 *   bank/cash account (that would be a disguised transfer), or an archived
 *   account.
 * - App\Support\CategoryAccountGuard (Phase 4 T2, money-OUT — expense/bill
 *   lines): the same control/bank-cash/archived rejections, PLUS an
 *   income-type account is rejected too (only expense/asset/liability/equity
 *   are eligible categories for money going out).
 *
 * Deliberately NOT a LedgerException: LedgerService is the frozen ledger
 * gate and must keep accepting bank/cash lines (they are legitimate for
 * transfers) and income lines (legitimate for invoice sends). Both
 * eligibility rules above are use-case-specific business rules, not ledger
 * iron rules, so each lives in — and is thrown from — its own guard, and is
 * caught by its own controller/FormRequest to surface a friendly validation
 * error on the relevant *_account_id field.
 */
class IneligibleCategoryAccount extends DomainException {}
