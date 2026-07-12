<?php

namespace Tests\Feature\Reports;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CashFlowClass;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\LedgerService;
use App\Services\Reports\BalanceSheetReport;
use App\Services\Reports\CashFlowReport;
use App\Services\Reports\LedgerBalances;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

/**
 * Phase 7 Task 4 (Statement of Cash Flows). The SIX exact-integer assertions
 * from the spec seed (§2) ARE the gate, per every test below:
 *   (1) Σdebit == Σcredit in-period (diagnostics.debits_equal_credits)
 *   (2) CFO+CFI+CFF+FX == closing-opening cash (net_change_in_cash /
 *       reconciles_to_cash)
 *   (3) CFO_indirect == CFO_direct (diagnostics.cfo_matches — the strongest
 *       cross-check: two independently-coded engines must agree)
 *   (4) SCF opening/closing cash == Balance Sheet cash at both dates
 *   (5) every cash line classified (diagnostics.unclassified_count === 0)
 *   (6) the depreciation add-back is sourced from the depreciation EXPENSE
 *       account, never accumulated depreciation's movement
 */
class CashFlowReportTest extends TestCase
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
            'cf_class' => CashFlowClass::Cash->value,
        ], $overrides));
    }

    private function secondBankAccount(Company $company, array $overrides = []): Account
    {
        return $this->bankAccount($company, array_merge(['code' => '1020', 'name' => 'Payroll Account'], $overrides));
    }

    /**
     * Deliberately subtyped CurrentAsset, NOT Receivable — Account's
     * isControlAccount() (subtype Receivable/Payable) requires every line to
     * carry a contact_id and forbids manual_journal source entirely
     * (LedgerService::post), which these balance-only tests have no need for
     * (mirrors BalanceSheetReportTest's identical workaround).
     */
    private function arAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '1100',
            'name' => 'Accounts Receivable',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::CurrentAsset->value,
            'cf_class' => CashFlowClass::Operating->value,
        ], $overrides));
    }

    /** Deliberately subtyped CurrentLiability, NOT Payable — see arAccount()'s docblock. */
    private function apAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '2000',
            'name' => 'Accounts Payable',
            'type' => AccountType::Liability->value,
            'subtype' => AccountSubtype::CurrentLiability->value,
            'cf_class' => CashFlowClass::Operating->value,
        ], $overrides));
    }

    private function fixedAssetAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '1500',
            'name' => 'Office Equipment',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::FixedAsset->value,
            'cf_class' => CashFlowClass::Investing->value,
        ], $overrides));
    }

    /** Deliberately no cf_class at all — proves the add-back never reads this account. */
    private function accumulatedDepreciationAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '1590',
            'name' => 'Accumulated Depreciation',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::FixedAsset->value,
            'cf_class' => null,
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
            'cf_class' => CashFlowClass::Operating->value,
        ], $overrides));
    }

    private function expenseAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '5000',
            'name' => 'Rent',
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
            'cf_class' => CashFlowClass::Operating->value,
        ], $overrides));
    }

    private function depreciationExpenseAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '5960',
            'name' => 'Depreciation Expense',
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
            'cf_class' => CashFlowClass::Investing->value,
        ], $overrides));
    }

    private function equityAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '3000',
            'name' => "Owner's Equity",
            'type' => AccountType::Equity->value,
            'subtype' => AccountSubtype::Equity->value,
            'cf_class' => CashFlowClass::Financing->value,
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
            'cf_class' => CashFlowClass::Financing->value,
            'is_system' => true,
        ], $overrides));
    }

    private function fxGainAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '4950',
            'name' => 'FX Gain',
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OtherIncome->value,
            'cf_class' => CashFlowClass::Excluded->value,
        ], $overrides));
    }

    /** @param list<LineDraft> $lines */
    private function postLines(array $lines, string $date, int $sourceId, string $sourceType = 'manual_journal', string $memo = 'Test entry'): JournalEntry
    {
        return DB::transaction(fn () => app(LedgerService::class)->post(new EntryDraft(
            entryDate: CarbonImmutable::parse($date),
            memo: $memo,
            sourceType: $sourceType,
            sourceId: $sourceId,
            lines: $lines,
        )));
    }

    private function line(Account $account, int $debit, int $credit): LineDraft
    {
        return new LineDraft(accountId: $account->id, debit: $debit, credit: $credit);
    }

    /** Posts a balanced two-line entry — the common case. */
    private function postEntry(Account $debitAccount, Account $creditAccount, int $amount, string $date, int $sourceId, string $sourceType = 'manual_journal', string $memo = 'Test entry'): JournalEntry
    {
        return $this->postLines([
            $this->line($debitAccount, $amount, 0),
            $this->line($creditAccount, 0, $amount),
        ], $date, $sourceId, $sourceType, $memo);
    }

    private function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return app(CashFlowReport::class)->build($from, $to);
    }

    // ---------------------------------------------------------------
    // The full scenario: invoices + payments + expenses + a depreciation-
    // style manual JE + a cash asset purchase -> all SIX assertions.
    // ---------------------------------------------------------------

    public function test_ties_out_on_a_company_with_invoices_payments_expenses_depreciation_and_an_asset_purchase(): void
    {
        $company = Company::factory()->create(['books_start_date' => '2026-01-01']);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);
        $depExpense = $this->depreciationExpenseAccount($company);
        $accumDep = $this->accumulatedDepreciationAccount($company);
        $fixedAsset = $this->fixedAssetAccount($company);

        $this->postEntry($ar, $income, 100000, '2026-07-01', 1, 'invoice', 'Invoice INV-0001');
        $this->postEntry($bank, $ar, 100000, '2026-07-10', 2, 'invoice_payment', 'Payment for INV-0001');
        $this->postEntry($expense, $bank, 20000, '2026-07-05', 3, 'expense', 'Office rent');
        $this->postEntry($depExpense, $accumDep, 5000, '2026-07-15', 4, 'manual_journal', 'Monthly depreciation');
        $this->postEntry($fixedAsset, $bank, 50000, '2026-07-20', 5, 'manual_journal', 'Bought equipment for cash');

        $from = CarbonImmutable::parse('2026-07-01');
        $to = CarbonImmutable::parse('2026-07-31');
        $report = $this->build($from, $to);

        // (1) Σdebit == Σcredit in-period.
        $this->assertTrue($report['diagnostics']['debits_equal_credits']);
        $this->assertLedgerBalances();

        // (3) CFO_indirect == CFO_direct — the strongest cross-check.
        $this->assertTrue($report['diagnostics']['cfo_matches']);
        $this->assertSame(80000, $report['diagnostics']['cfo_indirect']);
        $this->assertSame(80000, $report['diagnostics']['cfo_direct']);
        $this->assertSame(80000, $report['operating']['total']);

        // Investing: the cash asset purchase, nothing else.
        $this->assertSame(-50000, $report['investing']['total']);

        // Financing/FX: untouched by this scenario.
        $this->assertSame(0, $report['financing']['total']);
        $this->assertSame(0, $report['fx_on_cash']);

        // (2) CFO+CFI+CFF+FX == closing - opening cash.
        $this->assertSame(30000, $report['net_change_in_cash']);
        $this->assertSame(0, $report['opening_cash']);
        $this->assertSame(30000, $report['closing_cash']);
        $this->assertTrue($report['reconciles_to_cash']);

        // (4) SCF cash ties to the Balance Sheet's own cash row at both dates.
        $bsOpening = app(BalanceSheetReport::class)->build($from->subDay());
        $bsClosing = app(BalanceSheetReport::class)->build($to);
        $bsOpeningBankRow = collect($bsOpening['assets']['rows'])->firstWhere('account_id', $bank->id);
        $bsClosingBankRow = collect($bsClosing['assets']['rows'])->firstWhere('account_id', $bank->id);
        $this->assertSame($bsOpeningBankRow['amount'] ?? 0, $report['opening_cash']);
        $this->assertSame($bsClosingBankRow['amount'], $report['closing_cash']);

        // (5) Every cash line classified.
        $this->assertSame(0, $report['diagnostics']['unclassified_count']);
        $this->assertSame([], $report['diagnostics']['unclassified_lines']);

        // (6) The add-back is sourced from the DEPRECIATION EXPENSE account's
        // own movement, never accumulated depreciation's.
        $this->assertSame(5000, $report['operating']['add_backs']['total']);
        $addBackRow = collect($report['operating']['add_backs']['rows'])->firstWhere('account_id', $depExpense->id);
        $this->assertNotNull($addBackRow);
        $this->assertSame(5000, $addBackRow['amount']);

        $accumDepMovement = app(LedgerBalances::class)->movement($from, $to, [$accumDep->id]);
        $accumDepNet = $accumDepMovement[$accumDep->id]['net'] ?? 0;
        $this->assertSame(-5000, $accumDepNet);
        $this->assertNotSame($accumDepNet, $report['operating']['add_backs']['total']);
    }

    // ---------------------------------------------------------------
    // CFO_indirect == CFO_direct on a genuinely mixed period: partial AR
    // collection, AP increase + partial payment, a bank-to-bank transfer,
    // a financing injection, and an investing purchase.
    // ---------------------------------------------------------------

    public function test_cfo_indirect_equals_cfo_direct_on_a_mixed_period(): void
    {
        $company = Company::factory()->create(['books_start_date' => '2026-01-01']);
        app(CompanyContext::class)->set($company);
        $bank1 = $this->bankAccount($company);
        $bank2 = $this->secondBankAccount($company);
        $ar = $this->arAccount($company);
        $ap = $this->apAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);
        $fixedAsset = $this->fixedAssetAccount($company);
        $equity = $this->equityAccount($company);

        $this->postEntry($ar, $income, 200000, '2026-07-02', 1, 'invoice');
        $this->postEntry($bank1, $ar, 120000, '2026-07-05', 2, 'invoice_payment');
        $this->postEntry($expense, $ap, 60000, '2026-07-08', 3, 'bill');
        $this->postEntry($ap, $bank1, 40000, '2026-07-20', 4, 'bill_payment');
        $this->postEntry($bank2, $bank1, 30000, '2026-07-12', 5, 'transfer', 'Move funds to payroll account');
        $this->postEntry($bank1, $equity, 50000, '2026-07-15', 6, 'manual_journal', 'Capital injection');
        $this->postEntry($fixedAsset, $bank2, 25000, '2026-07-18', 7, 'manual_journal', 'Bought equipment for cash');

        $report = $this->build(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'));

        $this->assertTrue($report['diagnostics']['cfo_matches']);
        $this->assertSame(80000, $report['diagnostics']['cfo_indirect']);
        $this->assertSame(80000, $report['diagnostics']['cfo_direct']);
        $this->assertSame(-25000, $report['investing']['total']);
        $this->assertSame(50000, $report['financing']['total']);
        $this->assertSame(0, $report['fx_on_cash']);
        $this->assertSame(105000, $report['net_change_in_cash']);
        $this->assertSame(0, $report['opening_cash']);
        $this->assertSame(105000, $report['closing_cash']);
        $this->assertTrue($report['reconciles_to_cash']);
        $this->assertSame(0, $report['diagnostics']['unclassified_count']);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // A transfer between two bank accounts nets to zero — never appears
    // as investing or financing activity.
    // ---------------------------------------------------------------

    public function test_transfer_between_two_bank_accounts_nets_to_zero(): void
    {
        $company = Company::factory()->create(['books_start_date' => '2026-01-01']);
        app(CompanyContext::class)->set($company);
        $bank1 = $this->bankAccount($company);
        $bank2 = $this->secondBankAccount($company);
        $equity = $this->equityAccount($company);

        $this->postEntry($bank1, $equity, 100000, '2026-07-01', 1, 'manual_journal', 'Capital injection');
        $this->postEntry($bank2, $bank1, 40000, '2026-07-10', 2, 'transfer', 'Move funds to payroll account');

        $report = $this->build(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'));

        $this->assertSame(0, $report['operating']['total']);
        $this->assertSame(0, $report['investing']['total']);
        // Financing shows ONLY the real capital injection (100000) — the
        // transfer contributes exactly nothing, proving it nets to zero
        // rather than merely "isn't shown".
        $this->assertSame(100000, $report['financing']['total']);
        $this->assertSame(100000, $report['closing_cash']);
        $this->assertTrue($report['reconciles_to_cash']);
    }

    // ---------------------------------------------------------------
    // SCF opening/closing cash == Balance Sheet cash at both ends.
    // ---------------------------------------------------------------

    public function test_scf_cash_ties_to_balance_sheet_cash_at_both_dates(): void
    {
        $company = Company::factory()->create(['books_start_date' => '2026-01-01']);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);

        $this->postEntry($bank, $income, 80000, '2026-07-02', 1, 'invoice');
        $this->postEntry($expense, $bank, 30000, '2026-07-10', 2, 'expense');

        $from = CarbonImmutable::parse('2026-07-01');
        $to = CarbonImmutable::parse('2026-07-31');
        $report = $this->build($from, $to);

        $bsOpening = app(BalanceSheetReport::class)->build($from->subDay());
        $bsClosing = app(BalanceSheetReport::class)->build($to);
        $bsOpeningRow = collect($bsOpening['assets']['rows'])->firstWhere('account_id', $bank->id);
        $bsClosingRow = collect($bsClosing['assets']['rows'])->firstWhere('account_id', $bank->id);

        $this->assertSame($bsOpeningRow['amount'] ?? 0, $report['opening_cash']);
        $this->assertSame(0, $report['opening_cash']);
        $this->assertSame($bsClosingRow['amount'], $report['closing_cash']);
        $this->assertSame(50000, $report['closing_cash']);
    }

    // ---------------------------------------------------------------
    // A foreign-currency payment's realized FX effect shows on its own
    // "Effect of FX on cash" line, not buried inside Operating.
    // ---------------------------------------------------------------

    public function test_fx_payment_effect_shows_on_the_fx_on_cash_line_not_buried(): void
    {
        $company = Company::factory()->create(['books_start_date' => '2026-01-01']);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $fxGain = $this->fxGainAccount($company);

        // Invoice sent at its own booked base amount.
        $this->postEntry($ar, $income, 330400, '2026-07-02', 1, 'invoice');
        // Payment: actual base cash received (335000) differs from the AR
        // relief (330400, the invoice's own booked amount) -> the 4600
        // difference plugs to FX Gain (mirrors P2fx exactly).
        $this->postLines([
            $this->line($bank, 335000, 0),
            $this->line($ar, 0, 330400),
            $this->line($fxGain, 0, 4600),
        ], '2026-07-10', 2, 'invoice_payment', 'FX payment');

        $report = $this->build(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'));

        // The FX gain shows on its own line...
        $this->assertSame(4600, $report['fx_on_cash']);
        $this->assertSame(4600, $report['diagnostics']['fx_on_cash_indirect']);
        // ...and Operating reflects only the AR collection (330400) — the
        // FX gain is NOT buried inside it, even though Net Income (335000,
        // matching P&L) includes it before the reclass.
        $this->assertSame(335000, $report['operating']['net_income']);
        $this->assertSame(-4600, $report['operating']['fx_reclass']);
        $this->assertSame(330400, $report['operating']['total']);
        $this->assertTrue($report['diagnostics']['cfo_matches']);
        $this->assertSame(330400, $report['diagnostics']['cfo_direct']);
        $this->assertTrue($report['reconciles_to_cash']);
        $this->assertSame(0, $report['diagnostics']['unclassified_count']);
    }

    // ---------------------------------------------------------------
    // Empty company -> all zeros, reconciles.
    // ---------------------------------------------------------------

    public function test_empty_company_returns_all_zeros_and_reconciles(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);

        $report = $this->build(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'));

        $this->assertSame(0, $report['operating']['total']);
        $this->assertSame(0, $report['investing']['total']);
        $this->assertSame(0, $report['financing']['total']);
        $this->assertSame(0, $report['fx_on_cash']);
        $this->assertSame(0, $report['net_change_in_cash']);
        $this->assertSame(0, $report['opening_cash']);
        $this->assertSame(0, $report['closing_cash']);
        $this->assertTrue($report['reconciles_to_cash']);
        $this->assertTrue($report['diagnostics']['debits_equal_credits']);
        $this->assertTrue($report['diagnostics']['cfo_matches']);
        $this->assertSame(0, $report['diagnostics']['unclassified_count']);
    }

    // ---------------------------------------------------------------
    // An opening-balance entry (dated books_start - 1) does not pollute
    // a period starting at books_start.
    // ---------------------------------------------------------------

    public function test_opening_balance_entry_does_not_pollute_a_period_starting_at_books_start(): void
    {
        $company = Company::factory()->create(['books_start_date' => '2026-07-01']);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $obe = $this->obeAccount($company);
        $income = $this->incomeAccount($company);

        // Opening entry dated books_start_date - 1 day, mirrors
        // OpeningBalanceService::apply()'s P8a + plug shape exactly.
        $this->postEntry($bank, $obe, 500000, '2026-06-30', 1, 'opening_balance_run', 'Opening balances');
        // Real activity, inside the period.
        $this->postEntry($bank, $income, 20000, '2026-07-05', 2, 'invoice');

        $report = $this->build(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'));

        // The opening entry contributes nothing to ANY of the period's
        // cash-flow sections — it is entirely pre-period.
        $this->assertSame(0, $report['financing']['total']);
        $this->assertSame(20000, $report['operating']['total']);
        $this->assertSame(0, $report['investing']['total']);
        $this->assertTrue($report['diagnostics']['cfo_matches']);

        // ...but it DOES count toward opening cash (it happened before the
        // period, so it's part of the balance the period starts from).
        $this->assertSame(500000, $report['opening_cash']);
        $this->assertSame(520000, $report['closing_cash']);
        $this->assertSame(20000, $report['net_change_in_cash']);
        $this->assertTrue($report['reconciles_to_cash']);
    }
}
