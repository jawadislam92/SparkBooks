<?php

namespace App\Enums;

/**
 * Phase 7 Task 4 (Statement of Cash Flows): `accounts.cf_class` — classifies
 * every account for cash-flow purposes, independent of (but derived by
 * default from) `AccountType`/`AccountSubtype`. Nullable at the schema level
 * — a brand-new custom account an accountant hasn't classified yet is simply
 * null, which `CashFlowReport` surfaces as a loud "unclassified" finding
 * rather than silently guessing (assertion 5 in the spec seed, §2).
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
}
