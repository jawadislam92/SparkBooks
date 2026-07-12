<?php

namespace Tests\Feature\Reports;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Services\AccountService;
use App\Services\LedgerService;
use App\Services\Reports\AccountQuickReport;
use App\Services\Reports\LedgerBalances;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

/**
 * Account QuickReport (Phase 7 Task 5): one account's full transaction list
 * over a period -- the drill-down TARGET from P&L / Balance Sheet / Trial
 * Balance. Sign convention mirrors GeneralLedgerReport (see
 * AccountQuickReport's own class docblock). The one place this diverges
 * from GeneralLedgerReport: a PARENT account's QuickReport aggregates every
 * descendant leaf's lines into one combined, rolled-up list (GL leaves a
 * parent's line list empty) -- that rollup is this test file's central gate.
 */
class AccountQuickReportTest extends TestCase
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

    private function postEntry(
        Account $debitAccount,
        Account $creditAccount,
        int $amount,
        string $date,
        int $sourceId,
        string $sourceType = 'manual_journal',
        string $memo = 'Test entry',
    ): void {
        DB::transaction(fn () => app(LedgerService::class)->post(new EntryDraft(
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
    // LEAF account: only its own lines, opening ties to
    // cumulativeAsOf(from - 1 day), closing ties to cumulativeAsOf(to).
    // ---------------------------------------------------------------

    public function test_leaf_account_shows_only_its_own_lines_with_correct_opening_and_closing(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);

        $this->postEntry($bank, $income, 50000, '2026-06-15', 1); // before period -> folded into opening
        $this->postEntry($bank, $income, 10000, '2026-07-05', 2);
        $this->postEntry($expense, $bank, 4000, '2026-07-10', 3);

        $from = CarbonImmutable::parse('2026-07-01');
        $to = CarbonImmutable::parse('2026-07-31');

        $report = app(AccountQuickReport::class)->build($from, $to, $bank->id);

        $this->assertTrue($report['is_leaf']);
        $this->assertCount(2, $report['lines']); // only lines touching the bank account itself

        $expectedOpening = app(LedgerBalances::class)->cumulativeAsOf($from->subDay(), [$bank->id])[$bank->id]['net'];
        $this->assertSame($expectedOpening, $report['opening_balance']);
        $this->assertSame(50000, $report['opening_balance']);

        $expectedClosing = app(LedgerBalances::class)->cumulativeAsOf($to, [$bank->id])[$bank->id]['net'];
        $this->assertSame($expectedClosing, $report['closing_balance']);
        $this->assertSame(56000, $report['closing_balance']); // 50000 + 10000 - 4000

        $running = $report['opening_balance'];
        foreach ($report['lines'] as $line) {
            $running += $line['debit'] - $line['credit']; // bank is debit-normal
            $this->assertSame($running, $line['running_balance']);
        }
        $this->assertSame($running, $report['closing_balance']);
        $this->assertLedgerBalances();
    }

    public function test_leaf_account_ties_correctly_when_credit_normal(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postEntry($bank, $income, 20000, '2026-06-01', 1); // before period
        $this->postEntry($bank, $income, 5000, '2026-07-10', 2);

        $from = CarbonImmutable::parse('2026-07-01');
        $to = CarbonImmutable::parse('2026-07-31');

        $report = app(AccountQuickReport::class)->build($from, $to, $income->id);

        $cumulativeOpening = app(LedgerBalances::class)->cumulativeAsOf($from->subDay(), [$income->id])[$income->id]['net'];
        $this->assertSame(-$cumulativeOpening, $report['opening_balance']); // income is credit-normal -> flipped

        $cumulativeClosing = app(LedgerBalances::class)->cumulativeAsOf($to, [$income->id])[$income->id]['net'];
        $this->assertSame(-$cumulativeClosing, $report['closing_balance']);
    }

    // ---------------------------------------------------------------
    // PARENT account: aggregates every descendant leaf's lines into one
    // rolled-up, date-ordered list; a CHILD's own QuickReport shows only
    // its own lines.
    // ---------------------------------------------------------------

    public function test_parent_account_aggregates_descendant_lines_and_a_child_shows_only_its_own(): void
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

        $from = CarbonImmutable::parse('2026-07-01');
        $to = CarbonImmutable::parse('2026-07-31');

        $parentReport = app(AccountQuickReport::class)->build($from, $to, $parent->id);

        $this->assertFalse($parentReport['is_leaf']);
        $this->assertCount(2, $parentReport['lines']); // both children's lines, rolled up
        $this->assertSame(0, $parentReport['opening_balance']); // parent never carries a direct posting
        $this->assertSame(10000, $parentReport['closing_balance']); // 3000 + 7000

        // Ordered by date then account -- childA's line (07-01) before childB's (07-02).
        $this->assertSame($childA->id, $parentReport['lines'][0]['account_id']);
        $this->assertSame($childB->id, $parentReport['lines'][1]['account_id']);
        $this->assertSame(3000, $parentReport['lines'][0]['running_balance']);
        $this->assertSame(10000, $parentReport['lines'][1]['running_balance']);

        // A child's own QuickReport shows only its own line.
        $childAReport = app(AccountQuickReport::class)->build($from, $to, $childA->id);
        $this->assertTrue($childAReport['is_leaf']);
        $this->assertCount(1, $childAReport['lines']);
        $this->assertSame($childA->id, $childAReport['lines'][0]['account_id']);
        $this->assertSame(3000, $childAReport['closing_balance']);

        $childBReport = app(AccountQuickReport::class)->build($from, $to, $childB->id);
        $this->assertCount(1, $childBReport['lines']);
        $this->assertSame(7000, $childBReport['closing_balance']);
    }

    // ---------------------------------------------------------------
    // Opening-balance figure is correct even for an untouched account.
    // ---------------------------------------------------------------

    public function test_opening_balance_is_zero_for_a_never_touched_account(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $untouched = $this->expenseAccount($company, ['code' => '5999']);

        $report = app(AccountQuickReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
            $untouched->id,
        );

        $this->assertSame(0, $report['opening_balance']);
        $this->assertSame(0, $report['closing_balance']);
        $this->assertSame([], $report['lines']);
    }

    // ---------------------------------------------------------------
    // Drill params (account_id/from/to) resolve to the right filtered set.
    // ---------------------------------------------------------------

    public function test_drill_params_resolve_to_the_correct_account_and_period(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $this->postEntry($bank, $income, 10000, '2026-07-05', 1);
        $this->postEntry($income, $bank, 2000, '2026-08-06', 2); // outside the requested period

        $report = app(AccountQuickReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
            $bank->id,
        );

        $this->assertSame($bank->id, $report['account_id']);
        $this->assertSame('2026-07-01', $report['from']);
        $this->assertSame('2026-07-31', $report['to']);
        $this->assertCount(1, $report['lines']); // the August line is excluded
    }

    // ---------------------------------------------------------------
    // Cross-company isolation: a foreign account_id 404s (ModelNotFoundException).
    // ---------------------------------------------------------------

    public function test_report_scoped_to_current_company_a_foreign_account_id_is_not_found(): void
    {
        $companyA = Company::factory()->create();
        app(CompanyContext::class)->set($companyA);
        $bankA = $this->bankAccount($companyA);

        $companyB = Company::factory()->create();
        app(CompanyContext::class)->set($companyB);

        $this->expectException(ModelNotFoundException::class);

        app(AccountQuickReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
            $bankA->id,
        );
    }
}
