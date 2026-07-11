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
use App\Services\Reports\LedgerBalances;
use App\Services\Reports\TrialBalanceReport;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

/**
 * LedgerBalances is the shared ledger-aggregation core every report (P&L,
 * Balance Sheet, agings, GL, tax summary) must read journal_lines through —
 * so the load-bearing test here is the equivalence regression against
 * TrialBalanceReport: the existing, already-shipped report. If these two
 * ever disagree, some future report built on LedgerBalances would silently
 * show different numbers than Trial Balance for the same ledger.
 */
class LedgerBalancesTest extends TestCase
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
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OperatingIncome->value,
        ], $overrides));
    }

    private function otherIncomeAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '4100',
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OtherIncome->value,
        ], $overrides));
    }

    private function expenseAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '5000',
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
    // The load-bearing regression: LedgerBalances == TrialBalanceReport
    // ---------------------------------------------------------------

    public function test_cumulative_as_of_reproduces_trial_balance_report_exactly(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);
        $liability = $this->liabilityAccount($company);
        $otherIncome = $this->otherIncomeAccount($company);

        // An "invoice"-flavoured posting.
        $this->postEntry($bank, $income, 15000, '2026-07-01', 1, 'invoice');
        // An "expense"-flavoured posting.
        $this->postEntry($expense, $bank, 4000, '2026-07-03', 2, 'expense');
        // A manual JE.
        $this->postEntry($bank, $liability, 6000, '2026-07-05', 3, 'manual_journal');
        // Posted, then fully reversed: both entries remain in journal_lines
        // (the ledger is immutable) but net to zero.
        $reversed = $this->postEntry($otherIncome, $expense, 2500, '2026-07-06', 4, 'manual_journal', 'To be reversed');
        DB::transaction(fn () => app(LedgerService::class)->reverse($reversed, CarbonImmutable::parse('2026-07-07'), 'Reversal'));

        $asOf = CarbonImmutable::parse('2026-07-31');
        $tb = app(TrialBalanceReport::class)->build($asOf);
        $ledger = app(LedgerBalances::class)->cumulativeAsOf($asOf);

        $reconstructedDebit = $reconstructedCredit = 0;
        foreach ($tb['rows'] as $row) {
            $this->assertArrayHasKey($row['account_id'], $ledger, "Account {$row['code']} missing from LedgerBalances output.");
            $net = $ledger[$row['account_id']]['net'];
            $this->assertSame($row['debit'], max($net, 0), "Debit mismatch for account {$row['code']}.");
            $this->assertSame($row['credit'], max(-$net, 0), "Credit mismatch for account {$row['code']}.");
            $reconstructedDebit += max($net, 0);
            $reconstructedCredit += max(-$net, 0);
        }

        // Reconstructing TB's totals purely from LedgerBalances' nets (using
        // TB's own skip/max-net presentation rule) must equal TB's real totals.
        $this->assertSame($tb['total_debit'], $reconstructedDebit);
        $this->assertSame($tb['total_credit'], $reconstructedCredit);
        $this->assertGreaterThan(0, $tb['total_debit']);
        $this->assertLedgerBalances();
    }

    public function test_cumulative_as_of_matches_trial_balance_with_odd_paisa_amounts(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);
        $otherIncome = $this->otherIncomeAccount($company);

        $this->postEntry($bank, $income, 150050, '2026-07-01', 1); // Rs 1500.50
        $this->postEntry($expense, $otherIncome, 3333, '2026-07-02', 2); // Rs 33.33

        $asOf = CarbonImmutable::parse('2026-07-31');
        $tb = app(TrialBalanceReport::class)->build($asOf);
        $ledger = app(LedgerBalances::class)->cumulativeAsOf($asOf);

        foreach ($tb['rows'] as $row) {
            $net = $ledger[$row['account_id']]['net'];
            $this->assertSame($row['debit'], max($net, 0));
            $this->assertSame($row['credit'], max(-$net, 0));
        }
        $this->assertSame(153383, $tb['total_debit']);
    }

    // ---------------------------------------------------------------
    // Movement over a from..to range (P&L style)
    // ---------------------------------------------------------------

    public function test_movement_returns_only_lines_within_the_date_range(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postEntry($bank, $income, 10000, '2026-06-15', 1); // before range
        $this->postEntry($bank, $income, 5000, '2026-07-05', 2);  // in range
        $this->postEntry($bank, $income, 2000, '2026-07-20', 3);  // in range
        $this->postEntry($bank, $income, 3000, '2026-08-01', 4);  // after range

        $movement = app(LedgerBalances::class)->movement(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
        );

        $this->assertSame(7000, $movement[$bank->id]['debit']);
        $this->assertSame(0, $movement[$bank->id]['credit']);
        $this->assertSame(7000, $movement[$bank->id]['net']);
        $this->assertSame(0, $movement[$income->id]['debit']);
        $this->assertSame(7000, $movement[$income->id]['credit']);
    }

    public function test_movement_range_boundaries_are_inclusive(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postEntry($bank, $income, 1000, '2026-07-01', 1); // exactly on "from"
        $this->postEntry($bank, $income, 2000, '2026-07-31', 2); // exactly on "to"

        $movement = app(LedgerBalances::class)->movement(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
        );

        $this->assertSame(3000, $movement[$bank->id]['debit']);
    }

    // ---------------------------------------------------------------
    // Reversals are posted lines like any other — they net to zero, they
    // are not specially excluded.
    // ---------------------------------------------------------------

    public function test_reversed_entry_nets_to_zero_but_both_sides_remain_in_the_sums(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $entry = $this->postEntry($bank, $income, 8000, '2026-07-01', 1);
        DB::transaction(fn () => app(LedgerService::class)->reverse($entry, CarbonImmutable::parse('2026-07-02'), 'Reversal'));

        $balances = app(LedgerBalances::class)->cumulativeAsOf(CarbonImmutable::parse('2026-07-31'));

        $this->assertSame(8000, $balances[$bank->id]['debit']);
        $this->assertSame(8000, $balances[$bank->id]['credit']);
        $this->assertSame(0, $balances[$bank->id]['net']);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Empty company
    // ---------------------------------------------------------------

    public function test_company_with_no_posted_activity_returns_an_empty_array(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $this->bankAccount($company); // account exists but nothing was ever posted to it

        $asOf = CarbonImmutable::parse('2026-07-31');

        $this->assertSame([], app(LedgerBalances::class)->cumulativeAsOf($asOf));
        $this->assertSame([], app(LedgerBalances::class)->movement(CarbonImmutable::parse('2026-01-01'), $asOf));
    }

    // ---------------------------------------------------------------
    // Optional filters
    // ---------------------------------------------------------------

    public function test_cumulative_as_of_can_be_restricted_to_specific_account_ids(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);

        $this->postEntry($bank, $income, 5000, '2026-07-01', 1);
        $this->postEntry($expense, $bank, 1000, '2026-07-02', 2);

        $balances = app(LedgerBalances::class)->cumulativeAsOf(
            CarbonImmutable::parse('2026-07-31'),
            accountIds: [$bank->id],
        );

        $this->assertArrayHasKey($bank->id, $balances);
        $this->assertArrayNotHasKey($income->id, $balances);
        $this->assertArrayNotHasKey($expense->id, $balances);
    }

    public function test_cumulative_as_of_can_be_restricted_to_specific_account_types(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);

        $this->postEntry($bank, $income, 5000, '2026-07-01', 1);
        $this->postEntry($expense, $bank, 1000, '2026-07-02', 2);

        $balances = app(LedgerBalances::class)->cumulativeAsOf(
            CarbonImmutable::parse('2026-07-31'),
            accountTypes: [AccountType::Income, AccountType::Expense],
        );

        $this->assertArrayNotHasKey($bank->id, $balances);
        $this->assertArrayHasKey($income->id, $balances);
        $this->assertArrayHasKey($expense->id, $balances);
    }

    // ---------------------------------------------------------------
    // Cross-company isolation (§9.1 — never cross tenant boundaries)
    // ---------------------------------------------------------------

    public function test_only_includes_the_current_companys_lines(): void
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

        $balances = app(LedgerBalances::class)->cumulativeAsOf(CarbonImmutable::parse('2026-07-31'));

        $this->assertArrayHasKey($bankB->id, $balances);
        $this->assertArrayNotHasKey($bankA->id, $balances);
    }
}
