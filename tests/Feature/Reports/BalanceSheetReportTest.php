<?php

namespace Tests\Feature\Reports;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\LedgerService;
use App\Services\Reports\BalanceSheetReport;
use App\Services\Reports\ProfitAndLossReport;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

/**
 * §7.11.2 Retained Earnings rule under test throughout:
 *   Retained Earnings line = posted balance of account 3200
 *                             + net income of every COMPLETED fiscal year.
 *   Current-Year Earnings  = net income of the CURRENT (incomplete) fiscal
 *                             year, shown as its own separate equity line.
 *   Assets == Liabilities + Equity(raw equity excl. 3200 + RE + current-year)
 *   must hold as a mathematical identity, always — this is the hard
 *   invariant the whole test suite exists to pin down.
 *
 * SIGN CONVENTION: like TrialBalanceReport/ProfitAndLossReport, every
 * balance is presented in "positive is normal for that side" convention —
 * assets/expenses are debit-normal (net as-is), liabilities/equity/income
 * are credit-normal (net flipped, i.e. -net) — so a healthy Balance Sheet
 * reads as positive assets, positive liabilities, positive equity, with no
 * debit/credit language anywhere in the output.
 */
class BalanceSheetReportTest extends TestCase
{
    use AssertsLedgerBalanced;
    use RefreshDatabase;

    private function bankAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '1010',
            'name' => 'Bank Account',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Bank->value,
        ], $overrides));
    }

    /**
     * Deliberately subtyped CurrentAsset, NOT Receivable — Account's
     * isControlAccount() (subtype Receivable/Payable) requires every line to
     * carry a contact_id, which these balance-only tests have no need for.
     */
    private function arAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '1100',
            'name' => 'Accounts Receivable',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::CurrentAsset->value,
        ], $overrides));
    }

    private function liabilityAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '2000',
            'name' => 'Accounts Payable',
            'type' => AccountType::Liability->value,
            'subtype' => AccountSubtype::CurrentLiability->value,
        ], $overrides));
    }

    private function ownersEquityAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '3000',
            'name' => "Owner's Equity",
            'type' => AccountType::Equity->value,
            'subtype' => AccountSubtype::Equity->value,
        ], $overrides));
    }

    private function obeAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '3100',
            'name' => 'Opening Balance Equity',
            'type' => AccountType::Equity->value,
            'subtype' => AccountSubtype::Equity->value,
            'is_system' => true,
        ], $overrides));
    }

    private function retainedEarningsAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '3200',
            'name' => 'Retained Earnings',
            'type' => AccountType::Equity->value,
            'subtype' => AccountSubtype::Equity->value,
            'is_system' => true,
        ], $overrides));
    }

    private function drawingsAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '3900',
            'name' => 'Owner Drawings',
            'type' => AccountType::Equity->value,
            'subtype' => AccountSubtype::Equity->value,
        ], $overrides));
    }

    private function incomeAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '4000',
            'name' => 'Service Income',
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OperatingIncome->value,
        ], $overrides));
    }

    private function expenseAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '5000',
            'name' => 'Office Expenses',
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
        ], $overrides));
    }

    /** Posts a balanced two-line entry directly via LedgerService, inside the caller's transaction, as the brief requires. */
    private function postEntry(
        Account $debitAccount,
        Account $creditAccount,
        int $amount,
        string $date,
        int $sourceId,
        string $sourceType = 'manual_journal',
        string $memo = 'Test entry',
    ): JournalEntry {
        return DB::transaction(fn () => app(LedgerService::class)->post(new EntryDraft(
            entryDate: CarbonImmutable::parse($date),
            memo: $memo,
            sourceType: $sourceType,
            sourceId: $sourceId,
            lines: [
                new LineDraft(accountId: $debitAccount->id, debit: $amount, credit: 0),
                new LineDraft(accountId: $creditAccount->id, debit: 0, credit: $amount),
            ],
        )));
    }

    private function build(CarbonImmutable $asOf): array
    {
        return app(BalanceSheetReport::class)->build($asOf);
    }

    // ---------------------------------------------------------------
    // §12.9: the hard invariant — Assets == Liabilities + Equity.
    // ---------------------------------------------------------------

    public function test_balances_on_a_clean_company_with_invoices_expenses_payments_and_a_manual_je(): void
    {
        $company = Company::factory()->create(['books_start_date' => '2026-01-01']);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);
        $drawings = $this->drawingsAccount($company);

        // Invoice sent: Dr AR, Cr income.
        $this->postEntry($ar, $income, 100000, '2026-07-01', 1, 'invoice', 'Invoice INV-0001');
        // Payment received against it: Dr bank, Cr AR.
        $this->postEntry($bank, $ar, 100000, '2026-07-10', 2, 'invoice_payment', 'Payment for INV-0001');
        // Expense recorded: Dr expense, Cr bank.
        $this->postEntry($expense, $bank, 30000, '2026-07-05', 3, 'expense', 'Office expense');
        // Manual JE: owner draws cash out.
        $this->postEntry($drawings, $bank, 5000, '2026-07-15', 4, 'manual_journal', 'Owner draw');

        $report = $this->build(CarbonImmutable::parse('2026-07-31'));

        $this->assertSame($report['total_assets'], $report['total_liabilities_and_equity']);
        $this->assertTrue($report['is_balanced']);
        $this->assertSame(0, $report['difference']);
        $this->assertSame(65000, $report['total_assets']);
        $this->assertSame(0, $report['liabilities']['total']);
        $this->assertSame(70000, $report['equity']['current_year_earnings']);
        $this->assertSame(0, $report['equity']['retained_earnings']);
        $this->assertLedgerBalances();
    }

    public function test_balances_after_a_manual_je_posting_to_3200_prior_period_adjustment(): void
    {
        $company = Company::factory()->create(['books_start_date' => '2026-01-01']);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $re = $this->retainedEarningsAccount($company);

        // A prior-period adjustment posted straight to 3200.
        $this->postEntry($bank, $re, 10000, '2026-07-01', 1, 'manual_journal', 'Prior period adjustment');

        $report = $this->build(CarbonImmutable::parse('2026-07-31'));

        $this->assertSame(10000, $report['retained_earnings_detail']['posted_balance']);
        $this->assertSame(0, $report['retained_earnings_detail']['completed_fiscal_years_net_income']);
        $this->assertSame(10000, $report['equity']['retained_earnings']);
        $this->assertSame(10000, $report['total_assets']);
        $this->assertSame($report['total_assets'], $report['total_liabilities_and_equity']);
        $this->assertTrue($report['is_balanced']);
        $this->assertLedgerBalances();
    }

    public function test_balances_across_a_fiscal_year_boundary_with_non_january_fiscal_year_start(): void
    {
        // FY runs Jul 1 .. Jun 30.
        $company = Company::factory()->create([
            'fiscal_year_start_month' => 7,
            'books_start_date' => '2025-07-01',
        ]);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);

        // Prior (completed) FY: 2025-07-01 .. 2026-06-30.
        $this->postEntry($bank, $income, 50000, '2025-08-01', 1, 'invoice', 'Prior FY income');
        $this->postEntry($expense, $bank, 20000, '2025-09-01', 2, 'expense', 'Prior FY expense');

        // Current (incomplete) FY: 2026-07-01 .. 2027-06-30, as-of mid-way.
        $this->postEntry($bank, $income, 8000, '2026-07-05', 3, 'invoice', 'Current FY income');
        $this->postEntry($expense, $bank, 3000, '2026-07-10', 4, 'expense', 'Current FY expense');

        $asOf = CarbonImmutable::parse('2026-07-20');
        $report = $this->build($asOf);

        // Prior FY's net income (30000) rolled entirely into Retained
        // Earnings — NOT into current-year earnings.
        $this->assertSame(30000, $report['retained_earnings_detail']['completed_fiscal_years_net_income']);
        $this->assertSame(30000, $report['equity']['retained_earnings']);
        // Current-year earnings reflects ONLY the current FY's activity.
        $this->assertSame(5000, $report['equity']['current_year_earnings']);

        // Cross-check completed-FY window against ProfitAndLossReport directly.
        $priorFyNet = app(ProfitAndLossReport::class)->build(
            CarbonImmutable::parse('2025-07-01'),
            CarbonImmutable::parse('2026-06-30'),
        )['net_profit'];
        $this->assertSame(30000, $priorFyNet);
        $currentFyNet = app(ProfitAndLossReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            $asOf,
        )['net_profit'];
        $this->assertSame($currentFyNet, $report['equity']['current_year_earnings']);

        // The two windows never overlap or double-count: they sum to total
        // net income since inception.
        $this->assertSame(35000, $report['equity']['retained_earnings'] + $report['equity']['current_year_earnings']);

        $this->assertSame($report['total_assets'], $report['total_liabilities_and_equity']);
        $this->assertTrue($report['is_balanced']);
        $this->assertSame(35000, $report['total_assets']);
        $this->assertLedgerBalances();
    }

    public function test_current_year_earnings_equals_pl_net_for_the_current_fiscal_year(): void
    {
        $company = Company::factory()->create(['books_start_date' => '2026-01-01']);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);

        $this->postEntry($bank, $income, 12000, '2026-02-01', 1, 'invoice');
        $this->postEntry($expense, $bank, 4500, '2026-03-01', 2, 'expense');
        $this->postEntry($bank, $income, 3000, '2026-06-01', 3, 'invoice');

        $asOf = CarbonImmutable::parse('2026-07-15');
        $report = $this->build($asOf);

        $plNet = app(ProfitAndLossReport::class)->build(
            CarbonImmutable::parse('2026-01-01'),
            $asOf,
        )['net_profit'];

        $this->assertSame($plNet, $report['equity']['current_year_earnings']);
        $this->assertSame(10500, $report['equity']['current_year_earnings']);
    }

    // ---------------------------------------------------------------
    // RE composition: explicit assertion the line is EXACTLY
    // posted-3200 + Σ(completed-FY net income), nothing more or less.
    // ---------------------------------------------------------------

    public function test_retained_earnings_line_equals_3200_posted_balance_plus_completed_fy_net_income(): void
    {
        $company = Company::factory()->create([
            'fiscal_year_start_month' => 7,
            'books_start_date' => '2025-07-01',
        ]);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);
        $re = $this->retainedEarningsAccount($company);

        // Prior completed FY net income.
        $this->postEntry($bank, $income, 50000, '2025-08-01', 1, 'invoice');
        $this->postEntry($expense, $bank, 20000, '2025-09-01', 2, 'expense');
        // A prior-period adjustment posted directly to 3200 in the current FY.
        $this->postEntry($bank, $re, 2000, '2026-07-05', 3, 'manual_journal', 'Adjustment');

        $asOf = CarbonImmutable::parse('2026-07-20');
        $report = $this->build($asOf);

        $this->assertSame(2000, $report['retained_earnings_detail']['posted_balance']);
        $this->assertSame(30000, $report['retained_earnings_detail']['completed_fiscal_years_net_income']);
        $this->assertSame(
            $report['retained_earnings_detail']['posted_balance'] + $report['retained_earnings_detail']['completed_fiscal_years_net_income'],
            $report['equity']['retained_earnings'],
        );
        $this->assertSame(32000, $report['equity']['retained_earnings']);
        $this->assertTrue($report['is_balanced']);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Income/expense accounts must never leak into their own BS lines —
    // they only ever appear folded into RE / current-year earnings.
    // ---------------------------------------------------------------

    public function test_income_and_expense_accounts_do_not_appear_as_their_own_balance_sheet_lines(): void
    {
        $company = Company::factory()->create(['books_start_date' => '2026-01-01']);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);

        $this->postEntry($bank, $income, 10000, '2026-07-01', 1);
        $this->postEntry($expense, $bank, 4000, '2026-07-02', 2);

        $report = $this->build(CarbonImmutable::parse('2026-07-31'));

        $assetIds = collect($report['assets']['rows'])->pluck('account_id')->all();
        $liabilityIds = collect($report['liabilities']['rows'])->pluck('account_id')->all();
        $equityIds = collect($report['equity']['rows'])->pluck('account_id')->all();

        $this->assertNotContains($income->id, $assetIds);
        $this->assertNotContains($income->id, $liabilityIds);
        $this->assertNotContains($income->id, $equityIds);
        $this->assertNotContains($expense->id, $assetIds);
        $this->assertNotContains($expense->id, $liabilityIds);
        $this->assertNotContains($expense->id, $equityIds);
    }

    // ---------------------------------------------------------------
    // 3200 must never appear as a raw equity row — it is folded
    // entirely into the computed Retained Earnings line.
    // ---------------------------------------------------------------

    public function test_account_3200_never_appears_as_a_raw_equity_row(): void
    {
        $company = Company::factory()->create(['books_start_date' => '2026-01-01']);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $re = $this->retainedEarningsAccount($company);

        $this->postEntry($bank, $re, 5000, '2026-07-01', 1);

        $report = $this->build(CarbonImmutable::parse('2026-07-31'));

        $equityIds = collect($report['equity']['rows'])->pluck('account_id')->all();
        $this->assertNotContains($re->id, $equityIds);
        $this->assertSame(5000, $report['equity']['retained_earnings']);
    }

    // ---------------------------------------------------------------
    // OBE still shows as its own equity line when opening balances used.
    // ---------------------------------------------------------------

    public function test_obe_still_appears_if_opening_balances_used(): void
    {
        $company = Company::factory()->create(['books_start_date' => '2026-07-01']);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $obe = $this->obeAccount($company);

        // Opening entry dated books_start_date - 1 day, mirrors
        // OpeningBalanceService::apply()'s P8a + plug shape exactly.
        $this->postEntry($bank, $obe, 500000, '2026-06-30', 1, 'opening_balance_run', 'Opening balances');

        $report = $this->build(CarbonImmutable::parse('2026-07-31'));

        $obeRow = collect($report['equity']['rows'])->firstWhere('account_id', $obe->id);
        $this->assertNotNull($obeRow);
        $this->assertSame(500000, $obeRow['amount']);
        $this->assertSame(500000, $report['total_assets']);
        $this->assertTrue($report['is_balanced']);
    }

    // ---------------------------------------------------------------
    // Empty company: no accounts, no activity -> all zeros, balanced.
    // ---------------------------------------------------------------

    public function test_empty_company_returns_all_zeros_and_is_balanced(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);

        $report = $this->build(CarbonImmutable::parse('2026-07-31'));

        $this->assertSame(0, $report['total_assets']);
        $this->assertSame(0, $report['liabilities']['total']);
        $this->assertSame(0, $report['equity']['total']);
        $this->assertSame(0, $report['total_liabilities_and_equity']);
        $this->assertTrue($report['is_balanced']);
        $this->assertSame([], $report['assets']['rows']);
        $this->assertSame([], $report['liabilities']['rows']);
        $this->assertSame([], $report['equity']['rows']);
    }

    // ---------------------------------------------------------------
    // The opening entry (dated books_start_date - 1 day) must not
    // corrupt the completed-FY net-income rollup, even when
    // books_start_date doesn't land on a fiscal-year boundary — a
    // company with opening balances + a full completed FY + current
    // activity must still balance to the paisa.
    // ---------------------------------------------------------------

    public function test_opening_entry_does_not_corrupt_completed_fy_rollup(): void
    {
        // books_start_date deliberately NOT aligned to the Jan 1 fiscal
        // boundary, so the first "completed" FY window technically starts
        // before books_start_date — the exact scenario the T1 reviewer
        // flagged.
        $company = Company::factory()->create([
            'fiscal_year_start_month' => 1,
            'books_start_date' => '2026-03-15',
        ]);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $obe = $this->obeAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);

        // Opening entry: dated books_start_date - 1 day, touches only
        // Asset/Equity accounts — never Income/Expense.
        $this->postEntry($bank, $obe, 500000, '2026-03-14', 1, 'opening_balance_run', 'Opening balances');

        // A full completed FY's worth of real activity (2026, all after
        // books_start_date).
        $this->postEntry($bank, $income, 40000, '2026-04-01', 2, 'invoice');
        $this->postEntry($expense, $bank, 15000, '2026-05-01', 3, 'expense');

        // Current-year (2027) activity, as-of mid-way through.
        $this->postEntry($bank, $income, 6000, '2027-01-10', 4, 'invoice');
        $this->postEntry($expense, $bank, 2000, '2027-01-20', 5, 'expense');

        $asOf = CarbonImmutable::parse('2027-02-10');
        $report = $this->build($asOf);

        // The opening entry contributes nothing to net income — the
        // completed FY's net income is exactly the real 2026 activity.
        $this->assertSame(25000, $report['retained_earnings_detail']['completed_fiscal_years_net_income']);
        $this->assertSame(25000, $report['equity']['retained_earnings']);
        $this->assertSame(4000, $report['equity']['current_year_earnings']);

        $obeRow = collect($report['equity']['rows'])->firstWhere('account_id', $obe->id);
        $this->assertSame(500000, $obeRow['amount']);

        $this->assertSame(529000, $report['total_assets']);
        $this->assertSame($report['total_assets'], $report['total_liabilities_and_equity']);
        $this->assertTrue($report['is_balanced']);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Activity dated BEFORE books_start_date must still roll into
    // Retained Earnings — the exact accumulated-dev-data scenario that
    // exposed a completed-FY-rollup lower-bound bug (an expense dated
    // before books-start fell in NO window and was dropped, overstating
    // equity by that amount so the sheet didn't balance).
    // ---------------------------------------------------------------

    public function test_balances_when_activity_predates_books_start_date(): void
    {
        // books_start_date = 2026-01-01, but a 300,000 expense is dated
        // 2025-12-01 (BEFORE books-start). That expense's net-income impact
        // must land in Retained Earnings; anchoring the completed-FY rollup
        // at books_start_date would drop it entirely.
        $company = Company::factory()->create([
            'fiscal_year_start_month' => 1,
            'books_start_date' => '2026-01-01',
        ]);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);

        // Pre-books-start expense (belongs to the completed FY 2025).
        $this->postEntry($expense, $bank, 300000, '2025-12-01', 1, 'expense', 'Prior-period expense');
        // Current-FY (2026) activity.
        $this->postEntry($bank, $income, 500000, '2026-06-01', 2, 'invoice');

        $asOf = CarbonImmutable::parse('2026-07-15');
        $report = $this->build($asOf);

        // The 2025 expense (net income -300000) rolled into Retained
        // Earnings — NOT dropped, NOT in current-year earnings.
        $this->assertSame(-300000, $report['retained_earnings_detail']['completed_fiscal_years_net_income']);
        $this->assertSame(-300000, $report['equity']['retained_earnings']);
        // Current-year earnings reflects ONLY the current FY's activity.
        $this->assertSame(500000, $report['equity']['current_year_earnings']);

        // ...and the sheet STILL balances despite the pre-books activity.
        // Bank net: +500000 (invoice) - 300000 (expense) = +200000 assets.
        // Equity: -300000 (RE) + 500000 (current) = +200000. 200000 == 200000.
        $this->assertSame(200000, $report['total_assets']);
        $this->assertSame($report['total_assets'], $report['total_liabilities_and_equity']);
        $this->assertTrue($report['is_balanced']);
        $this->assertSame(0, $report['difference']);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Cross-company isolation.
    // ---------------------------------------------------------------

    public function test_report_only_includes_current_companys_lines(): void
    {
        $companyA = Company::factory()->create(['books_start_date' => '2026-01-01']);
        app(CompanyContext::class)->set($companyA);
        $bankA = $this->bankAccount($companyA);
        $incomeA = $this->incomeAccount($companyA);
        $this->postEntry($bankA, $incomeA, 10000, '2026-07-01', 1);

        $companyB = Company::factory()->create(['books_start_date' => '2026-01-01']);
        app(CompanyContext::class)->set($companyB);
        $bankB = $this->bankAccount($companyB);
        $incomeB = $this->incomeAccount($companyB);
        $this->postEntry($bankB, $incomeB, 5000, '2026-07-01', 2);

        $report = $this->build(CarbonImmutable::parse('2026-07-31'));

        $this->assertSame(5000, $report['total_assets']);
        $assetIds = collect($report['assets']['rows'])->pluck('account_id')->all();
        $this->assertContains($bankB->id, $assetIds);
        $this->assertNotContains($bankA->id, $assetIds);
    }

    // ---------------------------------------------------------------
    // Liability accounts show at their normal (credit) balance.
    // ---------------------------------------------------------------

    public function test_liability_account_shows_at_its_normal_credit_balance(): void
    {
        $company = Company::factory()->create(['books_start_date' => '2026-01-01']);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $liability = $this->liabilityAccount($company);

        $this->postEntry($bank, $liability, 6000, '2026-07-01', 1);

        $report = $this->build(CarbonImmutable::parse('2026-07-31'));

        $row = collect($report['liabilities']['rows'])->firstWhere('account_id', $liability->id);
        $this->assertSame(6000, $row['amount']);
        $this->assertSame(6000, $report['liabilities']['total']);
    }

    // ---------------------------------------------------------------
    // Drawings (a contra-equity account) correctly reduces total equity.
    // ---------------------------------------------------------------

    public function test_drawings_reduce_total_equity(): void
    {
        $company = Company::factory()->create(['books_start_date' => '2026-01-01']);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $equity = $this->ownersEquityAccount($company);
        $drawings = $this->drawingsAccount($company);

        $this->postEntry($bank, $equity, 100000, '2026-07-01', 1, 'manual_journal', 'Capital contribution');
        $this->postEntry($drawings, $bank, 15000, '2026-07-05', 2, 'manual_journal', 'Owner draw');

        $report = $this->build(CarbonImmutable::parse('2026-07-31'));

        $drawingsRow = collect($report['equity']['rows'])->firstWhere('account_id', $drawings->id);
        $this->assertSame(-15000, $drawingsRow['amount']);
        $this->assertSame(85000, $report['equity']['total']);
        $this->assertSame(85000, $report['total_assets']);
        $this->assertTrue($report['is_balanced']);
    }
}
