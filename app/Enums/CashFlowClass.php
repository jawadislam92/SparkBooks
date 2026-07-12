<?php

namespace App\Enums;

/**
 * Phase 7 Task 4 (Statement of Cash Flows): `accounts.cf_class` — classifies
 * every account for cash-flow purposes, independent of (but derived by
 * default from) `AccountType`/`AccountSubtype`. Nullable at the schema level
 * (a raw DB row could in principle hold NULL), but Phase 7 Task 8's fix made
 * that structurally unreachable through the app: `Account::booted()`'s
 * `saving` hook always fills a null `cf_class` in from `defaultFor()` below
 * before every save, so no account created or updated through Eloquent can
 * ever end up with one after the fact — see that hook's own docblock, and
 * `defaultFor()`'s, for the two ends of that guarantee.
 *
 * - Cash: the cash pool (cash/bank accounts) — SCF opening/closing cash is
 *   the sum of every account with this class, and `CashFlowReport::
 *   classifyCashLedger()` only ever scans lines posted to these accounts.
 * - Operating: working-capital assets/liabilities (AR, prepaid, other
 *   receivables, AP, accrued, deferred revenue, tax payable) AND, on an
 *   Income/Expense account, "this is an ordinary operating P&L item" (the
 *   default ChartOfAccountsSeeder gives every non-FX income/expense account).
 * - Investing: PP&E, intangibles, investments. ALSO repurposed, deliberately,
 *   on an EXPENSE account to mean "this is a non-cash charge whose economic
 *   effect is investing in nature" — i.e. Depreciation/Amortization Expense —
 *   so CashFlowReport's Operating add-back is driven by the SAME column,
 *   with no new schema needed (see CashFlowReport's class docblock, "THE
 *   DEPRECIATION ADD-BACK RULE").
 * - Financing: loans, leases principal, share capital, dividends.
 * - Excluded: never generically bucketed by CashFlowReport's per-account
 *   loops — reserved for accounts CashFlowReport special-cases by CODE
 *   instead (retained_earnings 3200, FX Gain/Loss 4950/5950 — see that
 *   class's docblock), or for a genuinely out-of-scope account a future
 *   accountant marks this way on purpose.
 */
enum CashFlowClass: string
{
    case Operating = 'operating';
    case Investing = 'investing';
    case Financing = 'financing';
    case Cash = 'cash';
    case Excluded = 'excluded';

    /**
     * Phase 7 Task 8 (cf_class fix): the ONE canonical derivation of a
     * default `cf_class` from an account's own `subtype`/`code` — encodes
     * EXACTLY the same mapping as the
     * `2026_07_12_000002_add_cf_class_to_accounts_table` migration's CASE
     * expression (which backfilled every pre-existing account once, and is
     * never re-run) and `ChartOfAccountsSeeder` (which now calls this method
     * directly instead of hardcoding its own copy of the mapping — see that
     * class). Called from two places:
     *   - `Account::booted()`'s `saving` hook, for EVERY account creation/
     *     update path in the app (ChartOfAccountsSeeder, BankAccountService,
     *     AccountController, AccountService::createChild, AccountFactory,
     *     future code) — the structural fix for the bug where an account
     *     created outside the three places that used to set `cf_class` was
     *     born with it NULL and silently fell out of the Statement of Cash
     *     Flows' cash pool / per-account classification.
     *   - `ChartOfAccountsSeeder` itself, so the seeded chart and the
     *     migration backfill can never drift apart from this mapping again.
     *
     * Special-cased codes (Retained Earnings 3200, FX Gain/Loss 4950/5950)
     * take priority over subtype — CashFlowReport special-cases these three
     * by CODE, never by cf_class alone (see that class's own docblock), so
     * their default is always Excluded regardless of what subtype they
     * happen to be.
     */
    public static function defaultFor(AccountSubtype $subtype, ?string $code = null): ?self
    {
        if ($code !== null && in_array($code, ['3200', '4950', '5950'], true)) {
            return self::Excluded;
        }

        return match ($subtype) {
            AccountSubtype::Cash, AccountSubtype::Bank => self::Cash,
            AccountSubtype::Receivable,
            AccountSubtype::CurrentAsset,
            AccountSubtype::Payable,
            AccountSubtype::Tax,
            AccountSubtype::CurrentLiability => self::Operating,
            AccountSubtype::FixedAsset => self::Investing,
            AccountSubtype::Equity => self::Financing,
            AccountSubtype::OperatingIncome,
            AccountSubtype::OtherIncome,
            AccountSubtype::OperatingExpense => self::Operating,
        };
    }
}
