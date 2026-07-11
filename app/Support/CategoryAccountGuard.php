<?php

namespace App\Support;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Exceptions\IneligibleCategoryAccount;
use App\Models\Account;
use Illuminate\Database\Eloquent\Builder;

/**
 * THE category-eligibility guard for expense/bill lines (spec §7.8, Phase 4
 * T2) — this is what makes tax remittances (P12, category = 2100 Tax
 * Payable), owner drawings (P13, category = an equity account like 3900
 * Owner Drawings), and fixed-asset purchases (P15, category = an asset
 * account like 1500 Office Equipment) recordable through ordinary
 * expense/bill screens instead of a manual journal entry.
 *
 * Mirrors OtherReceiptService::guardCategoryAccount (Phase 2 T7) in shape —
 * same DomainException, same "caller-independent" intent — but a DIFFERENT
 * rule: OtherReceipt is money-IN and allows income/equity with no type
 * whitelist beyond control/cash-bank; this is money-OUT and explicitly
 * requires the account's TYPE be expense/asset/liability/equity (i.e. NOT
 * income — an expense/bill categorized as income would be nonsensical).
 * Kept as its own class (not folded into OtherReceiptService) because the
 * two rules are genuinely different and Expense/Bill need to share this one
 * between themselves.
 *
 * Static/stateless by design, like InvoiceCalculator/DocumentLineCalculator
 * — it has no DB writes or dependencies of its own, just a decision function
 * over an already-fetched Account plus a matching query builder for the
 * picker-option list, kept in lockstep with guard() so the options a user is
 * ever offered are exactly the ones guard() would accept.
 */
class CategoryAccountGuard
{
    /** @var list<AccountType> */
    private const ELIGIBLE_TYPES = [
        AccountType::Expense,
        AccountType::Asset,
        AccountType::Liability,
        AccountType::Equity,
    ];

    /**
     * @throws IneligibleCategoryAccount when the account is archived, an
     *                                   income-type account, a control (AR/AP) account, or a bank/cash account.
     */
    public static function guard(Account $account): void
    {
        if ($account->is_archived) {
            throw new IneligibleCategoryAccount("Account {$account->code} is archived and cannot be used as a category.");
        }

        if (! in_array($account->type, self::ELIGIBLE_TYPES, true)) {
            throw new IneligibleCategoryAccount("Account {$account->code} is an income account and cannot be used as a category. Choose an expense, asset, liability, or equity account.");
        }

        if ($account->isControlAccount()) {
            throw new IneligibleCategoryAccount("Account {$account->code} tracks money owed to or from a customer or vendor and cannot be used as a category.");
        }

        if (in_array($account->subtype, [AccountSubtype::Cash, AccountSubtype::Bank], true)) {
            throw new IneligibleCategoryAccount("This account can't be used as a category. Choose an expense, asset, liability, or equity account instead of a bank/cash account.");
        }
    }

    /**
     * The tenant-scoped (BelongsToCompany global scope) query for every
     * account a category picker may offer — used by ExpenseController/
     * BillController's formOptions(). Deliberately expressed as the same
     * four rules as guard() above (archived / type whitelist / not a
     * receivable-or-payable subtype / not a cash-or-bank subtype) rather
     * than filtering guard()'s own exceptions in a loop, so the two stay
     * trivially comparable — see CategoryAccountGuardTest::
     * test_eligible_query_matches_guard_exactly.
     */
    public static function eligibleQuery(): Builder
    {
        return Account::query()
            ->active()
            ->whereIn('type', array_map(fn (AccountType $type) => $type->value, self::ELIGIBLE_TYPES))
            ->whereNotIn('subtype', [
                AccountSubtype::Receivable->value,
                AccountSubtype::Payable->value,
                AccountSubtype::Cash->value,
                AccountSubtype::Bank->value,
            ]);
    }
}
