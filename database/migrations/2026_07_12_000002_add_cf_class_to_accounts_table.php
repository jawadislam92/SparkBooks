<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 Task 4 (Statement of Cash Flows): one additive, nullable column —
 * `accounts.cf_class` (App\Enums\CashFlowClass — operating/investing/
 * financing/cash/excluded). Zero data movement: no journal_lines row is
 * touched, no account's code/name/type/subtype/parent_id changes.
 *
 * Backfill (same migration, one UPDATE, no PHP loop): every EXISTING account,
 * across every company, gets a sensible default derived from its own `code`
 * first, then its `subtype` — mirrors ChartOfAccountsSeeder's per-account
 * `cf_class` (the one place new companies get these same defaults going
 * forward; see that class for the authoritative account-by-account mapping
 * this CASE expression must stay in sync with):
 *
 * - code 3200 (Retained Earnings) and 4950/5950 (FX Gain/Loss) -> 'excluded'.
 *   CashFlowReport special-cases these three by CODE (never by cf_class
 *   alone) — 3200's raw movement is never used (NI + dividends already
 *   cover it) and 4950/5950 route to the dedicated "Effect of FX on cash"
 *   line rather than being buried in Operating. 'excluded' here just
 *   documents "not part of the generic per-account bucketing", consistent
 *   with CashFlowClass's own docblock.
 * - subtype cash/bank -> 'cash' (the pool CashFlowReport reconciles against
 *   the Balance Sheet's own cash/bank rows).
 * - subtype receivable/current_asset/payable/tax/current_liability ->
 *   'operating' (AR, prepaid/other current assets, AP, tax payable, accrued/
 *   deferred-revenue-shaped current liabilities — the spec seed §2 working-
 *   capital set).
 * - subtype fixed_asset -> 'investing' (PP&E).
 * - subtype equity (EXCEPT 3200 above) -> 'financing' (capital contributions,
 *   opening-balance equity, owner drawings/dividends).
 * - subtype operating_income/other_income/operating_expense (EXCEPT the two
 *   FX accounts above) -> 'operating' (ordinary P&L; NI already carries
 *   these, so 'operating' here just tells Engine A's cash-leg classifier
 *   "an ordinary income/expense other-leg is an Operating cash flow").
 *
 * `cf_class` stays NULL for any account this CASE doesn't recognize (there
 * are none in the default seed) — CashFlowReport surfaces that as a loud
 * "unclassified" finding rather than guessing (assertion 5), and it is
 * editable later exactly like `code`/`name`/`description` already are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $t) {
            $t->string('cf_class', 20)->nullable()->after('subtype');
        });

        DB::statement(<<<'SQL'
            UPDATE accounts SET cf_class = CASE
                WHEN code IN ('3200', '4950', '5950') THEN 'excluded'
                WHEN subtype IN ('cash', 'bank') THEN 'cash'
                WHEN subtype IN ('receivable', 'current_asset', 'payable', 'tax', 'current_liability') THEN 'operating'
                WHEN subtype = 'fixed_asset' THEN 'investing'
                WHEN subtype = 'equity' THEN 'financing'
                WHEN subtype IN ('operating_income', 'other_income', 'operating_expense') THEN 'operating'
                ELSE NULL
            END
        SQL);
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $t) {
            $t->dropColumn('cf_class');
        });
    }
};
