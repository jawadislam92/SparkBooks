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
use App\Services\AccountService;
use App\Services\LedgerService;
use App\Services\Reports\LedgerBalances;
use App\Services\Reports\TransactionDetailReport;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

/**
 * Transaction Detail by Account (Phase 7 Task 5): every posted journal_line
 * across a period, grouped by account, with a per-account running balance
 * that MUST tie to LedgerBalances::movement() for that account/period --
 * the load-bearing gate (see TransactionDetailReport's class docblock for
 * the exact sign convention, identical to GeneralLedgerReport/
 * TrialBalanceReport's "positive is normal" rule).
 */
class TransactionDetailReportTest extends TestCase
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
    ): void {
        DB::transaction(fn () => app(LedgerService::class)->post(new EntryDraft(
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
    // THE TIE-OUT: per-account net must equal LedgerBalances::movement().
    // ---------------------------------------------------------------

    public function test_per_account_net_ties_to_ledger_balances_movement_for_several_accounts(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);

        $this->postEntry($bank, $income, 50000, '2026-06-15', 1); // before period, not counted
        $this->postEntry($bank, $income, 10000, '2026-07-05', 2);
        $this->postEntry($expense, $bank, 4000, '2026-07-10', 3);
        $this->postEntry($bank, $income, 3000, '2026-07-20', 4);

        $from = CarbonImmutable::parse('2026-07-01');
        $to = CarbonImmutable::parse('2026-07-31');

        $report = app(TransactionDetailReport::class)->build($from, $to);
        $byId = collect($report['accounts'])->keyBy('account_id');

        foreach ([$bank, $income, $expense] as $account) {
            $isDebitNormal = $account->type->isDebitNormal();
            $movement = app(LedgerBalances::class)->movement($from, $to, [$account->id])[$account->id]['net'] ?? 0;
            $expectedNet = $isDebitNormal ? $movement : -$movement;
            $this->assertSame($expectedNet, $byId[$account->id]['net'], "Net mismatch for account {$account->code}.");
        }

        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Running balance steps consistently: each line's delta matches its own
    // movement, and the final running balance equals the account's net.
    // ---------------------------------------------------------------

    public function test_running_balance_is_internally_consistent_per_account(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postEntry($bank, $income, 2000, '2026-07-05', 1);
        $this->postEntry($bank, $income, 3000, '2026-07-10', 2);
        $this->postEntry($income, $bank, 1000, '2026-07-15', 3);

        $report = app(TransactionDetailReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
        );

        $byId = collect($report['accounts'])->keyBy('account_id');
        $bankRow = $byId[$bank->id];

        $running = 0;
        foreach ($bankRow['lines'] as $line) {
            $running += $line['debit'] - $line['credit']; // bank is debit-normal
            $this->assertSame($running, $line['running_balance']);
        }
        $this->assertSame($running, $bankRow['net']);
        $this->assertSame(4000, $bankRow['net']); // 2000 + 3000 - 1000
    }

    public function test_running_balance_ties_for_a_credit_normal_account(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postEntry($bank, $income, 5000, '2026-07-10', 1);
        $this->postEntry($bank, $income, 2000, '2026-07-20', 2);

        $report = app(TransactionDetailReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
        );

        $byId = collect($report['accounts'])->keyBy('account_id');
        $incomeRow = $byId[$income->id];

        $running = 0;
        foreach ($incomeRow['lines'] as $line) {
            $running += $line['credit'] - $line['debit']; // income is credit-normal
            $this->assertSame($running, $line['running_balance']);
        }
        $this->assertSame($running, $incomeRow['net']);
        $this->assertSame(7000, $incomeRow['net']);
    }

    // ---------------------------------------------------------------
    // Entry numbers, source type, contact carried on every line.
    // ---------------------------------------------------------------

    public function test_line_carries_entry_number_source_type_and_contact(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $receivable = $this->receivableAccount($company);
        $income = $this->incomeAccount($company);
        $contact = Contact::factory()->create(['company_id' => $company->id, 'type' => ContactType::Customer->value, 'name' => 'Acme Co']);

        $this->postEntry($receivable, $income, 10000, '2026-07-05', 42, 'invoice', 'Invoice INV-0042', $contact->id);

        $report = app(TransactionDetailReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
        );

        $byId = collect($report['accounts'])->keyBy('account_id');
        $line = $byId[$receivable->id]['lines'][0];

        $this->assertSame(1, $line['entry_number']);
        $this->assertSame('JE-000001', $line['entry_number_display']);
        $this->assertSame('invoice', $line['source_type']);
        $this->assertSame(42, $line['source_id']);
        $this->assertSame('Invoice #42', $line['source_label']);
        $this->assertSame($contact->id, $line['contact_id']);
        $this->assertSame('Acme Co', $line['contact_name']);
        $this->assertSame('Invoice INV-0042', $line['memo']);
        $this->assertSame('primary', $line['entry_role']);
        $this->assertNull($line['reverses_entry_number']);
    }

    // ---------------------------------------------------------------
    // Empty period -> zero rows, zero totals.
    // ---------------------------------------------------------------

    public function test_empty_period_has_zero_rows_and_zero_totals(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $this->postEntry($bank, $income, 10000, '2026-05-01', 1); // outside the queried period

        $report = app(TransactionDetailReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
        );

        $this->assertSame([], $report['accounts']);
        $this->assertSame(0, $report['grand_total_debit']);
        $this->assertSame(0, $report['grand_total_credit']);
    }

    // ---------------------------------------------------------------
    // Grand totals: with every account included (no filter), total debit ==
    // total credit -- every posted entry is itself balanced.
    // ---------------------------------------------------------------

    public function test_grand_totals_balance_when_every_account_is_included(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);

        $this->postEntry($bank, $income, 10000, '2026-07-01', 1);
        $this->postEntry($expense, $bank, 3000, '2026-07-05', 2);

        $report = app(TransactionDetailReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
        );

        $this->assertSame($report['grand_total_debit'], $report['grand_total_credit']);
        $this->assertSame(13000, $report['grand_total_debit']);
    }

    // ---------------------------------------------------------------
    // Only postable (leaf) accounts are ever listed -- a parent/header
    // account never carries a direct posting, so it never appears.
    // ---------------------------------------------------------------

    public function test_parent_header_accounts_never_appear_only_postable_leaves(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $parent = $this->expenseAccount($company, ['code' => '5400', 'name' => 'Software']);
        $service = app(AccountService::class);
        $child = $service->createChild($parent, ['code' => '5401', 'name' => 'Design Tools', 'subtype' => AccountSubtype::OperatingExpense]);

        $this->postEntry($child, $bank, 3000, '2026-07-01', 1);

        $report = app(TransactionDetailReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
        );

        $ids = collect($report['accounts'])->pluck('account_id')->all();
        $this->assertContains($child->id, $ids);
        $this->assertNotContains($parent->id, $ids);
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

        $report = app(TransactionDetailReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
        );

        $ids = collect($report['accounts'])->pluck('account_id')->all();
        $this->assertContains($bankB->id, $ids);
        $this->assertNotContains($bankA->id, $ids);
    }
}
