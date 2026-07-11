<?php

namespace Tests\Feature\Reports;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\AccountService;
use App\Services\LedgerService;
use App\Services\Reports\ProfitAndLossReport;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

/**
 * SIGN CONVENTION under test throughout: income accounts are credit-normal,
 * so LedgerBalances' net (debit − credit) is negative for a normal (credited)
 * income account — ProfitAndLossReport must flip that to a positive "Total
 * Income" figure. Expense accounts are debit-normal, so their net is already
 * positive and needs no flip. Net Profit = Total Income − Total Expenses.
 */
class ProfitAndLossReportTest extends TestCase
{
    use AssertsLedgerBalanced;
    use RefreshDatabase;

    private function bankAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '1010',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Bank->value,
        ], $overrides));
    }

    private function incomeAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '4000',
            'name' => 'Sales',
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OperatingIncome->value,
        ], $overrides));
    }

    private function otherIncomeAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '4100',
            'name' => 'Other income',
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OtherIncome->value,
        ], $overrides));
    }

    private function expenseAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '5000',
            'name' => 'Office expenses',
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
        ], $overrides));
    }

    private function liabilityAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '2000',
            'type' => AccountType::Liability->value,
            'subtype' => AccountSubtype::CurrentLiability->value,
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

    // ---------------------------------------------------------------
    // §12.9 tie-out: P&L net == Σ(income journal lines) − Σ(expense
    // journal lines) for the period, ties to the ledger directly.
    // ---------------------------------------------------------------

    public function test_net_profit_ties_to_raw_ledger_sums_for_the_period(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $otherIncome = $this->otherIncomeAccount($company);
        $expense = $this->expenseAccount($company);

        $this->postEntry($bank, $income, 10000, '2026-07-01', 1);
        $this->postEntry($bank, $otherIncome, 2500, '2026-07-05', 2);
        $this->postEntry($expense, $bank, 4000, '2026-07-10', 3);

        $from = CarbonImmutable::parse('2026-07-01');
        $to = CarbonImmutable::parse('2026-07-31');

        $rawIncomeCredit = (int) DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_lines.account_id', [$income->id, $otherIncome->id])
            ->whereBetween('journal_entries.entry_date', ['2026-07-01', '2026-07-31'])
            ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as v')
            ->value('v');

        $rawExpenseDebit = (int) DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $expense->id)
            ->whereBetween('journal_entries.entry_date', ['2026-07-01', '2026-07-31'])
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as v')
            ->value('v');

        $report = app(ProfitAndLossReport::class)->build($from, $to);

        $this->assertSame($rawIncomeCredit, $report['income']['total']);
        $this->assertSame($rawExpenseDebit, $report['expenses']['total']);
        $this->assertSame($rawIncomeCredit - $rawExpenseDebit, $report['net_profit']);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // The worked example from the brief.
    // ---------------------------------------------------------------

    public function test_worked_example_invoice_income_and_expense(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);

        // Invoice sending Rs 100,000 income (Cr 4000): AR debited, income credited.
        $this->postEntry($bank, $income, 100000, '2026-07-01', 1, 'invoice', 'Invoice INV-0001');
        // An expense of Rs 30,000 (Dr 5000): expense debited, bank credited.
        $this->postEntry($expense, $bank, 30000, '2026-07-10', 2, 'expense', 'Expense');

        $report = app(ProfitAndLossReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
        );

        $this->assertSame(100000, $report['income']['total']);
        $this->assertSame(30000, $report['expenses']['total']);
        $this->assertSame(70000, $report['net_profit']);

        $incomeRow = collect($report['income']['rows'])->firstWhere('account_id', $income->id);
        $this->assertSame(100000, $incomeRow['amount']);

        $expenseRow = collect($report['expenses']['rows'])->firstWhere('account_id', $expense->id);
        $this->assertSame(30000, $expenseRow['amount']);
    }

    // ---------------------------------------------------------------
    // Loss period: expenses exceed income -> negative net, correctly signed.
    // ---------------------------------------------------------------

    public function test_loss_period_shows_negative_net_profit(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);

        $this->postEntry($bank, $income, 20000, '2026-07-01', 1, 'invoice');
        $this->postEntry($expense, $bank, 50000, '2026-07-05', 2, 'expense');

        $report = app(ProfitAndLossReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
        );

        $this->assertSame(20000, $report['income']['total']);
        $this->assertSame(50000, $report['expenses']['total']);
        $this->assertSame(-30000, $report['net_profit']);
    }

    // ---------------------------------------------------------------
    // Voided invoice + voided expense: the reversal is a posted line
    // in-range like any other, so it nets the account back to zero.
    // ---------------------------------------------------------------

    public function test_voided_invoice_and_voided_expense_net_to_zero_in_period(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);

        $sentInvoice = $this->postEntry($bank, $income, 100000, '2026-07-01', 1, 'invoice', 'Invoice INV-0002');
        DB::transaction(fn () => app(LedgerService::class)->reverse($sentInvoice, CarbonImmutable::parse('2026-07-15'), 'Void invoice'));

        $recordedExpense = $this->postEntry($expense, $bank, 30000, '2026-07-02', 2, 'expense', 'Expense');
        DB::transaction(fn () => app(LedgerService::class)->reverse($recordedExpense, CarbonImmutable::parse('2026-07-16'), 'Void expense'));

        $report = app(ProfitAndLossReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
        );

        $this->assertSame(0, $report['income']['total']);
        $this->assertSame(0, $report['expenses']['total']);
        $this->assertSame(0, $report['net_profit']);
        // A fully-offset account contributes zero movement -> omitted from display.
        $this->assertSame([], $report['income']['rows']);
        $this->assertSame([], $report['expenses']['rows']);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Monthly split sums to the range total.
    // ---------------------------------------------------------------

    public function test_monthly_split_sums_to_the_range_total(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);

        $this->postEntry($bank, $income, 10000, '2026-01-15', 1, 'invoice');
        $this->postEntry($bank, $income, 20000, '2026-02-10', 2, 'invoice');
        $this->postEntry($bank, $income, 5000, '2026-03-20', 3, 'invoice');
        $this->postEntry($expense, $bank, 3000, '2026-01-05', 4, 'expense');
        $this->postEntry($expense, $bank, 7000, '2026-03-01', 5, 'expense');

        $report = app(ProfitAndLossReport::class)->build(
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-03-31'),
        );

        $this->assertCount(3, $report['months']);
        $this->assertSame('2026-01-01', $report['months'][0]['start']);
        $this->assertSame('2026-01-31', $report['months'][0]['end']);
        $this->assertSame('2026-03-01', $report['months'][2]['start']);
        $this->assertSame('2026-03-31', $report['months'][2]['end']);

        // Per-account months sum to the account's range amount.
        $incomeRow = collect($report['income']['rows'])->firstWhere('account_id', $income->id);
        $this->assertSame(35000, $incomeRow['amount']);
        $this->assertSame(35000, array_sum($incomeRow['months']));
        $this->assertSame([10000, 20000, 5000], $incomeRow['months']);

        $expenseRow = collect($report['expenses']['rows'])->firstWhere('account_id', $expense->id);
        $this->assertSame(10000, $expenseRow['amount']);
        $this->assertSame([3000, 0, 7000], $expenseRow['months']);

        // Section totals-by-month sum to the section range total.
        $this->assertSame($report['income']['total'], array_sum($report['income']['total_by_month']));
        $this->assertSame($report['expenses']['total'], array_sum($report['expenses']['total_by_month']));

        // Net profit by month sums to the range net profit.
        $this->assertSame($report['net_profit'], array_sum($report['net_profit_by_month']));
        $this->assertSame([7000, 20000, -2000], $report['net_profit_by_month']);
    }

    public function test_cross_month_post_then_void_still_shows_each_months_real_activity(): void
    {
        // Regression: a zero-FULL-RANGE-net account must not zero out the
        // months it actually had activity in. Income posted in January and
        // voided in February nets to 0 over Jan–Feb, but January's column
        // must still show the real income and February's the reversal.
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $januaryInvoice = $this->postEntry($bank, $income, 100000, '2026-01-15', 1, 'invoice', 'Invoice INV-0100');
        DB::transaction(fn () => app(LedgerService::class)->reverse($januaryInvoice, CarbonImmutable::parse('2026-02-10'), 'Void invoice'));

        $report = app(ProfitAndLossReport::class)->build(
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-02-28'),
        );

        // Full-range nets to zero -> no display row, zero range total.
        $this->assertSame(0, $report['income']['total']);
        $this->assertSame([], $report['income']['rows']);

        // ...but the monthly totals must NOT be zeroed: January shows the real
        // income, February shows the reversal.
        $this->assertSame([100000, -100000], $report['income']['total_by_month']);
        // And they still tie out to the (zero) range total.
        $this->assertSame($report['income']['total'], array_sum($report['income']['total_by_month']));

        // Net profit by month reflects the same, and sums to the range net.
        $this->assertSame([100000, -100000], $report['net_profit_by_month']);
        $this->assertSame($report['net_profit'], array_sum($report['net_profit_by_month']));
    }

    public function test_monthly_split_clips_partial_first_and_last_months(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postEntry($bank, $income, 1000, '2026-01-20', 1, 'invoice'); // in first (clipped) bucket
        $this->postEntry($bank, $income, 2000, '2026-02-10', 2, 'invoice'); // in the second (clipped) bucket

        $report = app(ProfitAndLossReport::class)->build(
            CarbonImmutable::parse('2026-01-15'),
            CarbonImmutable::parse('2026-02-15'),
        );

        $this->assertCount(2, $report['months']);
        $this->assertSame('2026-01-15', $report['months'][0]['start']);
        $this->assertSame('2026-01-31', $report['months'][0]['end']);
        $this->assertSame('2026-02-01', $report['months'][1]['start']);
        $this->assertSame('2026-02-15', $report['months'][1]['end']);

        $incomeRow = collect($report['income']['rows'])->firstWhere('account_id', $income->id);
        $this->assertSame([1000, 2000], $incomeRow['months']);
        $this->assertSame(3000, array_sum($incomeRow['months']));
        $this->assertSame(3000, $report['income']['total']);
    }

    // ---------------------------------------------------------------
    // Zero-movement accounts are omitted from display.
    // ---------------------------------------------------------------

    public function test_account_with_zero_movement_is_omitted(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $unusedIncome = $this->incomeAccount($company, ['code' => '4999']);
        $unusedExpense = $this->expenseAccount($company, ['code' => '5999']);
        $bank = $this->bankAccount($company);
        $activeIncome = $this->otherIncomeAccount($company);

        $this->postEntry($bank, $activeIncome, 5000, '2026-07-01', 1, 'invoice');

        $report = app(ProfitAndLossReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
        );

        $incomeIds = collect($report['income']['rows'])->pluck('account_id')->all();
        $this->assertNotContains($unusedIncome->id, $incomeIds);
        $this->assertContains($activeIncome->id, $incomeIds);

        $expenseIds = collect($report['expenses']['rows'])->pluck('account_id')->all();
        $this->assertNotContains($unusedExpense->id, $expenseIds);
    }

    // ---------------------------------------------------------------
    // Empty period: no accounts, no activity -> zeros, no error.
    // ---------------------------------------------------------------

    public function test_empty_period_returns_all_zeros_without_error(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);

        $report = app(ProfitAndLossReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
        );

        $this->assertSame([], $report['income']['rows']);
        $this->assertSame(0, $report['income']['total']);
        $this->assertSame([], $report['expenses']['rows']);
        $this->assertSame(0, $report['expenses']['total']);
        $this->assertSame(0, $report['net_profit']);
        $this->assertCount(1, $report['months']);
    }

    public function test_inverted_range_returns_zeros_without_error(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $this->postEntry($bank, $income, 5000, '2026-07-01', 1);

        $report = app(ProfitAndLossReport::class)->build(
            CarbonImmutable::parse('2026-07-31'),
            CarbonImmutable::parse('2026-07-01'),
        );

        $this->assertSame([], $report['months']);
        $this->assertSame(0, $report['income']['total']);
        $this->assertSame(0, $report['net_profit']);
    }

    // ---------------------------------------------------------------
    // Multi-currency note: P&L reads journal_lines, which always hold
    // BASE-currency minor units (P1fx converts at send time) — so an FX
    // invoice's base income appears correctly with no special-casing.
    // ---------------------------------------------------------------

    public function test_fx_invoice_base_currency_income_appears_correctly(): void
    {
        $company = Company::factory()->create(['base_currency' => 'PKR']);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        // A USD 100 invoice at fx_rate 280 converts to base PKR 28,000 (2800000
        // minor units) BEFORE it is ever posted (P1fx) -- the ledger only ever
        // sees the already-converted base amount, exactly like any other line.
        $this->postEntry($bank, $income, 2800000, '2026-07-01', 1, 'invoice', 'Invoice INV-0003 (USD 100 @ 280)');

        $report = app(ProfitAndLossReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
        );

        $this->assertSame(2800000, $report['income']['total']);
        $this->assertSame(2800000, $report['net_profit']);
    }

    // ---------------------------------------------------------------
    // Cross-company isolation.
    // ---------------------------------------------------------------

    public function test_report_only_includes_current_companys_lines(): void
    {
        $companyA = Company::factory()->create();
        app(CompanyContext::class)->set($companyA);
        $bankA = $this->bankAccount($companyA);
        $incomeA = $this->incomeAccount($companyA);
        $this->postEntry($bankA, $incomeA, 10000, '2026-07-01', 1);

        $companyB = Company::factory()->create();
        app(CompanyContext::class)->set($companyB);
        $bankB = $this->bankAccount($companyB);
        $incomeB = $this->incomeAccount($companyB);
        $this->postEntry($bankB, $incomeB, 5000, '2026-07-01', 2);

        $report = app(ProfitAndLossReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
        );

        $this->assertSame(5000, $report['income']['total']);
        $incomeIds = collect($report['income']['rows'])->pluck('account_id')->all();
        $this->assertContains($incomeB->id, $incomeIds);
        $this->assertNotContains($incomeA->id, $incomeIds);
    }

    // ---------------------------------------------------------------
    // Non-income/expense accounts never leak into either section.
    // ---------------------------------------------------------------

    public function test_asset_and_liability_accounts_never_appear_in_the_report(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $liability = $this->liabilityAccount($company);
        $income = $this->incomeAccount($company);

        $this->postEntry($bank, $liability, 6000, '2026-07-01', 1);
        $this->postEntry($bank, $income, 4000, '2026-07-02', 2);

        $report = app(ProfitAndLossReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
        );

        $incomeIds = collect($report['income']['rows'])->pluck('account_id')->all();
        $expenseIds = collect($report['expenses']['rows'])->pluck('account_id')->all();
        $this->assertNotContains($bank->id, $incomeIds);
        $this->assertNotContains($bank->id, $expenseIds);
        $this->assertNotContains($liability->id, $incomeIds);
        $this->assertNotContains($liability->id, $expenseIds);
    }

    // ---------------------------------------------------------------
    // Phase 7 Task 1: sub-account rollup
    // ---------------------------------------------------------------

    public function test_expense_parent_with_two_posting_children_shows_each_leaf_and_a_correct_subtotal(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $parent = $this->expenseAccount($company, ['code' => '5400', 'name' => 'Software']);
        $service = app(AccountService::class);
        $childA = $service->createChild($parent, ['code' => '5401', 'name' => 'Design Tools', 'subtype' => AccountSubtype::OperatingExpense]);
        $childB = $service->createChild($parent->fresh(), ['code' => '5402', 'name' => 'Dev Tools', 'subtype' => AccountSubtype::OperatingExpense]);

        $this->postEntry($childA, $bank, 3000, '2026-07-01', 1);
        $this->postEntry($childB, $bank, 7000, '2026-07-02', 2);

        $report = app(ProfitAndLossReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
        );

        $byId = collect($report['expenses']['rows'])->keyBy('account_id');
        $this->assertSame(3000, $byId[$childA->id]['amount']);
        $this->assertFalse($byId[$childA->id]['is_subtotal']);
        $this->assertSame(7000, $byId[$childB->id]['amount']);

        $parentRow = $byId[$parent->id];
        $this->assertSame(10000, $parentRow['amount']);
        $this->assertTrue($parentRow['is_subtotal']);
        $this->assertSame([10000], $parentRow['months']);

        // Display-only subtotal — must not double the section total.
        $this->assertSame(10000, $report['expenses']['total']);
        $this->assertSame([10000], $report['expenses']['total_by_month']);
        $this->assertSame(-10000, $report['net_profit']);
    }
}
