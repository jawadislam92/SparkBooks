<?php

namespace Tests\Feature\Reports;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ContactType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Services\AccountService;
use App\Services\LedgerService;
use App\Services\Reports\GeneralLedgerReport;
use App\Services\Reports\LedgerBalances;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

/**
 * SIGN CONVENTION under test throughout (documented on GeneralLedgerReport
 * itself): the running/opening/closing balance is presented in the
 * account's own NATURAL side — identical to the existing
 * AccountController::show drill-down and to every other Phase 5 report's
 * "positive is normal" convention. Debit-normal accounts (Asset, Expense)
 * show raw net (debit − credit); credit-normal accounts (Liability, Equity,
 * Income) show the flipped net (credit − debit). So the load-bearing tie
 * is: closing_balance === (isDebitNormal ? 1 : -1) * cumulativeAsOf(to)[account]['net'].
 */
class GeneralLedgerReportTest extends TestCase
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

    private function receivableAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '1100',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Receivable->value,
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
        ?int $contactId = null,
    ): JournalEntry {
        return DB::transaction(fn () => app(LedgerService::class)->post(new EntryDraft(
            entryDate: CarbonImmutable::parse($date),
            memo: $memo,
            sourceType: $sourceType,
            sourceId: $sourceId,
            lines: [
                new LineDraft(accountId: $debitAccount->id, debit: $amount, credit: 0, contactId: $contactId),
                new LineDraft(accountId: $creditAccount->id, debit: 0, credit: $amount, contactId: $contactId),
            ],
        )));
    }

    // ---------------------------------------------------------------
    // The load-bearing tie: opening + Σ(line movements) == closing ==
    // LedgerBalances::cumulativeAsOf(to) for the account.
    // ---------------------------------------------------------------

    public function test_running_balance_is_internally_consistent_and_ties_to_ledger_balances(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);

        // Some activity BEFORE the period (folded into opening), and some
        // WITHIN [from, to].
        $this->postEntry($bank, $income, 50000, '2026-06-15', 1);
        $this->postEntry($bank, $income, 10000, '2026-07-05', 2);
        $this->postEntry($expense, $bank, 4000, '2026-07-10', 3);
        $this->postEntry($bank, $income, 3000, '2026-07-20', 4);

        $from = CarbonImmutable::parse('2026-07-01');
        $to = CarbonImmutable::parse('2026-07-31');

        $report = app(GeneralLedgerReport::class)->build($from, $to, $bank->id);
        $account = $report['accounts'][0];

        $movementSum = 0;
        $running = $account['opening_balance'];
        foreach ($account['lines'] as $line) {
            $movementSum += $line['debit'] - $line['credit'];
            $running += $line['debit'] - $line['credit'];
            $this->assertSame($running, $line['running_balance']);
        }

        $this->assertSame($account['opening_balance'] + $movementSum, $account['closing_balance']);

        $cumulativeToDate = app(LedgerBalances::class)->cumulativeAsOf($to, [$bank->id]);
        $this->assertSame($cumulativeToDate[$bank->id]['net'], $account['closing_balance']); // bank is debit-normal: no flip needed
        $this->assertLedgerBalances();
    }

    public function test_running_balance_ties_for_a_credit_normal_account(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postEntry($bank, $income, 20000, '2026-06-01', 1); // before period
        $this->postEntry($bank, $income, 5000, '2026-07-10', 2);
        $this->postEntry($bank, $income, 2000, '2026-07-20', 3);

        $from = CarbonImmutable::parse('2026-07-01');
        $to = CarbonImmutable::parse('2026-07-31');

        $report = app(GeneralLedgerReport::class)->build($from, $to, $income->id);
        $account = $report['accounts'][0];

        $running = $account['opening_balance'];
        foreach ($account['lines'] as $line) {
            $running += $line['credit'] - $line['debit']; // income is credit-normal
            $this->assertSame($running, $line['running_balance']);
        }
        $this->assertSame($running, $account['closing_balance']);

        $cumulativeToDate = app(LedgerBalances::class)->cumulativeAsOf($to, [$income->id]);
        // Income is credit-normal -> displayed net is flipped (-net).
        $this->assertSame(-$cumulativeToDate[$income->id]['net'], $account['closing_balance']);
    }

    // ---------------------------------------------------------------
    // Opening balance == cumulativeAsOf(from - 1 day); lines before `from`
    // are folded into opening, never listed individually.
    // ---------------------------------------------------------------

    public function test_opening_balance_equals_cumulative_as_of_the_day_before_from(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postEntry($bank, $income, 15000, '2026-06-01', 1);
        $this->postEntry($bank, $income, 8000, '2026-06-30', 2); // day before `from`
        $this->postEntry($bank, $income, 1000, '2026-07-01', 3); // exactly on `from`

        $from = CarbonImmutable::parse('2026-07-01');
        $to = CarbonImmutable::parse('2026-07-31');

        $report = app(GeneralLedgerReport::class)->build($from, $to, $bank->id);
        $account = $report['accounts'][0];

        $expectedOpening = app(LedgerBalances::class)->cumulativeAsOf(
            CarbonImmutable::parse('2026-06-30'),
            [$bank->id],
        )[$bank->id]['net'];

        $this->assertSame($expectedOpening, $account['opening_balance']);
        $this->assertSame(23000, $account['opening_balance']); // 15000 + 8000, folded in
        $this->assertCount(1, $account['lines']); // only the 2026-07-01 line is listed
    }

    // ---------------------------------------------------------------
    // Per-account filter returns ONLY that account's lines.
    // ---------------------------------------------------------------

    public function test_per_account_filter_returns_only_that_accounts_lines(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);

        $this->postEntry($bank, $income, 10000, '2026-07-01', 1);
        $this->postEntry($expense, $bank, 3000, '2026-07-05', 2);

        $report = app(GeneralLedgerReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
            $bank->id,
        );

        $this->assertCount(1, $report['accounts']);
        $this->assertSame($bank->id, $report['accounts'][0]['account_id']);
        // Both lines that touched the bank account (as debit and as credit) appear.
        $this->assertCount(2, $report['accounts'][0]['lines']);
    }

    // ---------------------------------------------------------------
    // A reversal within the period shows BOTH the original and the
    // reversal line -- they don't cancel in the listing.
    // ---------------------------------------------------------------

    public function test_reversal_within_period_shows_both_original_and_reversal_lines(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $entry = $this->postEntry($bank, $income, 10000, '2026-07-05', 1, 'invoice', 'Invoice INV-0001');
        DB::transaction(fn () => app(LedgerService::class)->reverse($entry, CarbonImmutable::parse('2026-07-15'), 'Void invoice'));

        $report = app(GeneralLedgerReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
            $bank->id,
        );

        $lines = $report['accounts'][0]['lines'];
        $this->assertCount(2, $lines);

        // Ordered by date then id -- original first, reversal second.
        $this->assertSame('2026-07-05', $lines[0]['entry_date']);
        $this->assertSame('primary', $lines[0]['entry_role']);
        $this->assertSame(10000, $lines[0]['debit']);

        $this->assertSame('2026-07-15', $lines[1]['entry_date']);
        $this->assertSame('reversal', $lines[1]['entry_role']);
        $this->assertSame(10000, $lines[1]['credit']);

        // Running balance steps up then back down to the account's opening.
        $this->assertSame($report['accounts'][0]['opening_balance'] + 10000, $lines[0]['running_balance']);
        $this->assertSame($report['accounts'][0]['opening_balance'], $lines[1]['running_balance']);
        $this->assertSame($report['accounts'][0]['opening_balance'], $report['accounts'][0]['closing_balance']);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Lines ordered by date then id; running balance monotonic per the
    // steps (each step's delta matches its own line's movement).
    // ---------------------------------------------------------------

    public function test_lines_ordered_by_date_then_id(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        // Post out of chronological order (by sourceId) but with distinct dates.
        $this->postEntry($bank, $income, 1000, '2026-07-20', 3);
        $this->postEntry($bank, $income, 2000, '2026-07-05', 1);
        $this->postEntry($bank, $income, 3000, '2026-07-05', 2); // same date as previous -> tie-break by line id

        $report = app(GeneralLedgerReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
            $bank->id,
        );

        $lines = $report['accounts'][0]['lines'];
        $this->assertSame(['2026-07-05', '2026-07-05', '2026-07-20'], array_column($lines, 'entry_date'));
        $this->assertSame(2000, $lines[0]['debit']);
        $this->assertSame(3000, $lines[1]['debit']);
        $this->assertSame(1000, $lines[2]['debit']);
        $this->assertTrue($lines[0]['id'] < $lines[1]['id']);
    }

    // ---------------------------------------------------------------
    // Empty account (no lines in range) -> opening == closing, zero
    // lines, no error.
    // ---------------------------------------------------------------

    public function test_empty_account_has_no_lines_and_opening_equals_closing(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company); // never posted to

        $report = app(GeneralLedgerReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
            $bank->id,
        );

        $this->assertCount(1, $report['accounts']);
        $account = $report['accounts'][0];
        $this->assertSame([], $account['lines']);
        $this->assertSame(0, $account['opening_balance']);
        $this->assertSame($account['opening_balance'], $account['closing_balance']);
    }

    // ---------------------------------------------------------------
    // Multi-account (all accounts) view groups correctly + each
    // account's closing ties to its own cumulativeAsOf(to).
    // ---------------------------------------------------------------

    public function test_all_accounts_view_groups_correctly_and_each_closing_ties(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);

        $this->postEntry($bank, $income, 10000, '2026-07-01', 1);
        $this->postEntry($expense, $bank, 3000, '2026-07-05', 2);

        $report = app(GeneralLedgerReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
        );

        $this->assertNull($report['account_id']);
        $byId = collect($report['accounts'])->keyBy('account_id');
        $this->assertArrayHasKey($bank->id, $byId->all());
        $this->assertArrayHasKey($income->id, $byId->all());
        $this->assertArrayHasKey($expense->id, $byId->all());

        foreach ($report['accounts'] as $accountRow) {
            $isDebitNormal = AccountType::from($accountRow['type'])->isDebitNormal();
            $net = app(LedgerBalances::class)->cumulativeAsOf(
                CarbonImmutable::parse('2026-07-31'),
                [$accountRow['account_id']],
            )[$accountRow['account_id']]['net'] ?? 0;
            $expectedClosing = $isDebitNormal ? $net : -$net;
            $this->assertSame($expectedClosing, $accountRow['closing_balance'], "Closing mismatch for account {$accountRow['code']}.");
        }
    }

    public function test_all_accounts_view_omits_never_touched_accounts(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $unused = $this->expenseAccount($company, ['code' => '5999']);

        $this->postEntry($bank, $income, 10000, '2026-07-01', 1);

        $report = app(GeneralLedgerReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
        );

        $ids = collect($report['accounts'])->pluck('account_id')->all();
        $this->assertNotContains($unused->id, $ids);
    }

    // ---------------------------------------------------------------
    // A directly-selected account is ALWAYS included, even with zero
    // activity (never silently skipped like the "all accounts" view).
    // ---------------------------------------------------------------

    public function test_directly_selected_empty_account_is_never_skipped(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $untouched = $this->expenseAccount($company, ['code' => '5999']);

        $report = app(GeneralLedgerReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
            $untouched->id,
        );

        $this->assertCount(1, $report['accounts']);
        $this->assertSame($untouched->id, $report['accounts'][0]['account_id']);
    }

    // ---------------------------------------------------------------
    // Contact filter narrows the listed lines (drill-down convenience;
    // opening/closing still reflect the whole account -- see class
    // docblock).
    // ---------------------------------------------------------------

    public function test_contact_filter_narrows_lines_to_that_contact_only(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $receivable = $this->receivableAccount($company);
        $income = $this->incomeAccount($company);
        $contactA = Contact::factory()->create(['company_id' => $company->id, 'type' => ContactType::Customer->value]);
        $contactB = Contact::factory()->create(['company_id' => $company->id, 'type' => ContactType::Customer->value]);

        $this->postEntry($receivable, $income, 10000, '2026-07-01', 1, 'invoice', 'Invoice A', $contactA->id);
        $this->postEntry($receivable, $income, 5000, '2026-07-02', 2, 'invoice', 'Invoice B', $contactB->id);

        $report = app(GeneralLedgerReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
            $receivable->id,
            $contactA->id,
        );

        $lines = $report['accounts'][0]['lines'];
        $this->assertCount(1, $lines);
        $this->assertSame($contactA->id, $lines[0]['contact_id']);
        $this->assertSame(10000, $lines[0]['debit']);
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

        $report = app(GeneralLedgerReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
        );

        $ids = collect($report['accounts'])->pluck('account_id')->all();
        $this->assertContains($bankB->id, $ids);
        $this->assertNotContains($bankA->id, $ids);
    }

    // ---------------------------------------------------------------
    // Source label + entry ref shape.
    // ---------------------------------------------------------------

    public function test_line_carries_source_and_entry_reference(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $entry = $this->postEntry($bank, $income, 10000, '2026-07-01', 42, 'invoice', 'Invoice INV-0042');

        $report = app(GeneralLedgerReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
            $bank->id,
        );

        $line = $report['accounts'][0]['lines'][0];
        $this->assertSame($entry->id, $line['entry_id']);
        $this->assertSame('invoice', $line['source_type']);
        $this->assertSame(42, $line['source_id']);
        $this->assertSame('Invoice #42', $line['source_label']);
        $this->assertSame('Invoice INV-0042', $line['memo']);
    }

    // ---------------------------------------------------------------
    // Phase 7 Task 1: sub-account rollup
    // ---------------------------------------------------------------

    public function test_parent_with_two_posting_children_shows_each_leaf_and_a_rolled_up_subtotal(): void
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

        $report = app(GeneralLedgerReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
        );

        $byId = collect($report['accounts'])->keyBy('account_id');

        $this->assertSame(3000, $byId[$childA->id]['closing_balance']);
        $this->assertFalse($byId[$childA->id]['is_subtotal']);
        $this->assertCount(1, $byId[$childA->id]['lines']);

        $this->assertSame(7000, $byId[$childB->id]['closing_balance']);

        $parentRow = $byId[$parent->id];
        $this->assertTrue($parentRow['is_subtotal']);
        $this->assertSame(0, $parentRow['opening_balance']);
        $this->assertSame(10000, $parentRow['closing_balance']);
        $this->assertSame([], $parentRow['lines']);
    }
}
