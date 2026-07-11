<?php

namespace Tests\Feature;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BillStatus;
use App\Enums\ContactType;
use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\Reports\AgingReport;
use App\Services\Reports\ProfitAndLossReport;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Task 10 — the Dashboard's Phase 2 "light" real-data pass: cash position +
 * customer/vendor counts (DashboardController), plus the `can` ability map
 * the sidebar nav (AppShell.tsx) reads to decide what to render.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(Company $company, Role $role): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($company, ['role' => $role->value]);
        $user->forceFill(['current_company_id' => $company->id])->save();
        $this->actingAs($user);

        return $user;
    }

    /** Gives a bank account a real ledger balance via a manual journal entry. */
    private function depositInto(BankAccount $bankAccount, int $amountMinor): void
    {
        $incomeAccount = Account::factory()->create([
            'code' => (string) fake()->unique()->numerify('49##'),
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OperatingIncome->value,
        ]);

        $this->postJson('/journal-entries', [
            'entry_date' => '2026-07-01',
            'memo' => 'Deposit',
            'lines' => [
                ['account_id' => $bankAccount->ledger_account_id, 'debit' => $amountMinor, 'credit' => 0],
                ['account_id' => $incomeAccount->id, 'debit' => 0, 'credit' => $amountMinor],
            ],
        ], ['X-Idempotency-Key' => (string) Str::uuid()])->assertRedirect(route('journal-entries.index'));
    }

    // ---------------------------------------------------------------
    // Cash position + counts
    // ---------------------------------------------------------------

    public function test_dashboard_reports_cash_position_and_contact_counts_for_an_onboarded_user(): void
    {
        $company = Company::factory()->create(['base_currency' => 'PKR']);
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);

        $main = BankAccount::factory()->create(['name' => 'Main', 'currency_code' => 'PKR']);
        $petty = BankAccount::factory()->create(['name' => 'Petty cash', 'type' => 'cash', 'currency_code' => 'PKR']);
        // Archived accounts must not count toward cash position or the count.
        BankAccount::factory()->create(['name' => 'Closed', 'currency_code' => 'PKR', 'is_archived' => true]);

        $this->depositInto($main, 75000);
        $this->depositInto($petty, 5000);

        Contact::factory()->count(2)->create(['type' => ContactType::Customer->value]);
        Contact::factory()->create(['type' => ContactType::Vendor->value]);
        Contact::factory()->create(['type' => ContactType::Both->value]); // counts as both
        Contact::factory()->create(['type' => ContactType::Customer->value, 'is_archived' => true]); // excluded

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('cash_position', 80000)
                ->where('customer_count', 3) // 2 customer + 1 both
                ->where('vendor_count', 2) // 1 vendor + 1 both
                ->where('bank_account_count', 2) // archived excluded
            );
    }

    public function test_dashboard_reports_zeros_for_a_freshly_onboarded_company(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cash_position', 0)
                ->where('customer_count', 0)
                ->where('vendor_count', 0)
                ->where('bank_account_count', 0)
                // Task 7: every enrichment widget zeros out cleanly on an
                // empty company — no error, no division-by-zero, no null.
                ->where('invoices.outstanding_count', 0)
                ->where('invoices.outstanding_total', 0)
                ->where('invoices.overdue_count', 0)
                ->where('invoices.overdue_total', 0)
                ->where('bills.outstanding_count', 0)
                ->where('bills.outstanding_total', 0)
                ->where('bills.overdue_count', 0)
                ->where('bills.overdue_total', 0)
                ->where('pl_snapshot.income', 0)
                ->where('pl_snapshot.expenses', 0)
                ->where('pl_snapshot.net_profit', 0)
                ->where('income_expense_chart.income', array_fill(0, 6, 0))
                ->where('income_expense_chart.expenses', array_fill(0, 6, 0))
                // No ActivityLog rows exist yet for a company created directly
                // via the factory (bypassing onboarding's own audit log).
                ->where('recent_activity', [])
            );
    }

    public function test_dashboard_figures_are_tenant_scoped(): void
    {
        $companyA = Company::factory()->create(['base_currency' => 'PKR']);
        app(CompanyContext::class)->set($companyA);
        $bankA = BankAccount::factory()->create(['currency_code' => 'PKR']);
        Contact::factory()->count(3)->create(['type' => ContactType::Customer->value]);

        $this->actingAsRole($companyA, Role::Owner);
        $this->depositInto($bankA, 12345);

        // Company A also has an outstanding invoice, an outstanding bill, and
        // an audit-log row — none of which company B may ever see.
        $today = CarbonImmutable::today();
        Invoice::factory()->create([
            'status' => InvoiceStatus::Sent->value,
            'due_date' => $today->addDays(10)->toDateString(),
            'balance' => 7500,
        ]);
        Bill::factory()->create([
            'status' => BillStatus::Received->value,
            'due_date' => $today->addDays(10)->toDateString(),
            'balance' => 3300,
        ]);
        $this->post('/contacts', ['name' => 'Scoped Co', 'type' => ContactType::Customer->value])
            ->assertRedirect();

        $companyB = Company::factory()->create(['base_currency' => 'PKR']);
        $this->actingAsRole($companyB, Role::Owner);

        // Company B has none of company A's bank accounts, contacts,
        // invoices, bills, or activity-log rows.
        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cash_position', 0)
                ->where('customer_count', 0)
                ->where('vendor_count', 0)
                ->where('bank_account_count', 0)
                ->where('invoices.outstanding_count', 0)
                ->where('invoices.outstanding_total', 0)
                ->where('bills.outstanding_count', 0)
                ->where('bills.outstanding_total', 0)
                ->where('recent_activity', [])
            );
    }

    // ---------------------------------------------------------------
    // Task 7: outstanding/overdue invoices, unpaid bills.
    // ---------------------------------------------------------------

    public function test_dashboard_reports_outstanding_and_overdue_invoices(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);

        // The invoice widget's TOTALS come from the AR aging report (base-
        // correct, ledger-tied — see the mixed-currency test below and the
        // controller docblock), so the outstanding ones need real AR ledger
        // postings behind them; the COUNTS come per-invoice from the model.
        // Every invoice here is base-currency (PKR fx 1), so balance == the
        // base AR amount and the two agree by construction.
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $today = CarbonImmutable::today();
        $issued = $today->subDays(40)->toDateString();

        // Outstanding, not yet due -> counts toward outstanding only.
        $notYetDue = Invoice::factory()->create([
            'status' => InvoiceStatus::Sent->value,
            'due_date' => $today->addDays(10)->toDateString(),
            'balance' => 10000,
        ]);
        $this->postArEntry($ar, $income, $notYetDue, 10000, $issued);

        // Outstanding AND overdue (past due, still unpaid).
        $overdue = Invoice::factory()->create([
            'status' => InvoiceStatus::PartiallyPaid->value,
            'due_date' => $today->subDays(5)->toDateString(),
            'balance' => 4000,
        ]);
        $this->postArEntry($ar, $income, $overdue, 4000, $issued);

        // Outstanding with a null due_date -> NEVER overdue (spec §7.12).
        $noDueDate = Invoice::factory()->create([
            'status' => InvoiceStatus::Sent->value,
            'due_date' => null,
            'balance' => 2500,
        ]);
        $this->postArEntry($ar, $income, $noDueDate, 2500, $issued);

        // Due exactly today -> not yet overdue (strictly less-than, not <=).
        $dueToday = Invoice::factory()->create([
            'status' => InvoiceStatus::Sent->value,
            'due_date' => $today->toDateString(),
            'balance' => 1000,
        ]);
        $this->postArEntry($ar, $income, $dueToday, 1000, $issued);

        // Paid -> excluded (balance 0, not outstanding). No live AR: its
        // posting (if any) has been fully relieved, so aging drops it too.
        Invoice::factory()->create([
            'status' => InvoiceStatus::Paid->value,
            'due_date' => $today->subDays(30)->toDateString(),
            'balance' => 0,
        ]);

        // Draft -> excluded (never sent, never posted).
        Invoice::factory()->create([
            'status' => InvoiceStatus::Draft->value,
            'due_date' => $today->subDays(30)->toDateString(),
            'balance' => 0,
        ]);

        // Void -> excluded.
        Invoice::factory()->create([
            'status' => InvoiceStatus::Void->value,
            'due_date' => $today->subDays(30)->toDateString(),
            'balance' => 0,
        ]);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('invoices.outstanding_count', 4)
                ->where('invoices.outstanding_total', 10000 + 4000 + 2500 + 1000)
                ->where('invoices.overdue_count', 1)
                ->where('invoices.overdue_total', 4000)
            );
    }

    public function test_dashboard_invoice_totals_are_base_correct_for_mixed_currency_and_tie_to_ar_control(): void
    {
        // The bug this guards: summing Invoice.balance (DOCUMENT currency) and
        // labelling it base currency understates a foreign-currency invoice by
        // (fx_rate − 1)×. The widget instead reads AR aging (base-correct,
        // journal_lines-derived, ties to the 1100 control account).
        $company = Company::factory()->create(['base_currency' => 'PKR']);
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);

        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $contact = Contact::factory()->create();
        $today = CarbonImmutable::today();
        $issued = $today->subDays(20)->toDateString();

        // Domestic PKR invoice: PKR 1,000 (100000 minor). Document == base.
        // Not yet due -> outstanding but not overdue.
        $pkrInvoice = Invoice::factory()->create([
            'contact_id' => $contact->id,
            'status' => InvoiceStatus::Sent->value,
            'currency_code' => 'PKR',
            'fx_rate' => 1,
            'due_date' => $today->addDays(10)->toDateString(),
            'total' => 100000,
            'balance' => 100000,
            'base_total' => 100000,
        ]);
        $this->postArEntry($ar, $income, $pkrInvoice, 100000, $issued);

        // Foreign USD invoice: USD 100.00 (10000 minor DOCUMENT) @ fx 280 =
        // base PKR 28,000 (2800000 minor). balance stays document currency;
        // the AR ledger posting is in BASE (P1fx converts before posting).
        // Past due -> outstanding AND overdue.
        $usdInvoice = Invoice::factory()->create([
            'contact_id' => $contact->id,
            'status' => InvoiceStatus::Sent->value,
            'currency_code' => 'USD',
            'fx_rate' => 280,
            'due_date' => $today->subDays(5)->toDateString(),
            'total' => 10000,
            'balance' => 10000,
            'base_total' => 2800000,
        ]);
        $this->postArEntry($ar, $income, $usdInvoice, 2800000, $issued);

        $naiveDocSum = 100000 + 10000;        // 110000 — the OLD, wrong figure
        $baseCorrect = 100000 + 2800000;      // 2900000 — base AR outstanding
        $this->assertNotSame($naiveDocSum, $baseCorrect, 'premise: the FX invoice makes the two diverge');

        // The AR aging itself agrees and ties to the 1100 control balance.
        $aging = app(AgingReport::class)->buildReceivables($today);
        $this->assertSame($baseCorrect, $aging['grand_total']);
        $this->assertTrue($aging['ties_to_control']);
        $this->assertSame($aging['control_balance'], $aging['grand_total']);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('invoices.outstanding_count', 2)
                // Base-correct + ledger-tied, NOT the naive document-currency
                // sum (110000) the old code produced.
                ->where('invoices.outstanding_total', $baseCorrect)
                ->where('invoices.outstanding_total', 2900000)
                ->where('invoices.overdue_count', 1)
                // Just the USD invoice is past due -> its base AR (2800000),
                // NOT its document balance (10000).
                ->where('invoices.overdue_total', 2800000)
            );
    }

    public function test_dashboard_reports_unpaid_bills_and_their_overdue_subset(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);

        $today = CarbonImmutable::today();

        // Outstanding, not yet due.
        Bill::factory()->create([
            'status' => BillStatus::Received->value,
            'due_date' => $today->addDays(15)->toDateString(),
            'balance' => 8000,
        ]);

        // Outstanding AND overdue.
        Bill::factory()->create([
            'status' => BillStatus::PartiallyPaid->value,
            'due_date' => $today->subDays(3)->toDateString(),
            'balance' => 2200,
        ]);

        // Null due_date -> never overdue.
        Bill::factory()->create([
            'status' => BillStatus::Received->value,
            'due_date' => null,
            'balance' => 1500,
        ]);

        // Paid -> excluded.
        Bill::factory()->create([
            'status' => BillStatus::Paid->value,
            'due_date' => $today->subDays(10)->toDateString(),
            'balance' => 0,
        ]);

        // Draft/void -> excluded.
        Bill::factory()->create([
            'status' => BillStatus::Draft->value,
            'due_date' => $today->subDays(10)->toDateString(),
            'balance' => 0,
        ]);
        Bill::factory()->create([
            'status' => BillStatus::Void->value,
            'due_date' => $today->subDays(10)->toDateString(),
            'balance' => 0,
        ]);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('bills.outstanding_count', 3)
                ->where('bills.outstanding_total', 8000 + 2200 + 1500)
                ->where('bills.overdue_count', 1)
                ->where('bills.overdue_total', 2200)
            );
    }

    // ---------------------------------------------------------------
    // Task 7: P&L snapshot + income-vs-expense chart tie to
    // ProfitAndLossReport (no new ledger math on the dashboard).
    // ---------------------------------------------------------------

    private function bankAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '1010',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Bank->value,
        ]);
    }

    private function incomeAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '4000',
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OperatingIncome->value,
        ]);
    }

    private function expenseAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '5000',
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
        ]);
    }

    private function arAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '1100',
            'name' => 'Accounts Receivable',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Receivable->value,
        ]);
    }

    /** Posts a balanced two-line entry directly via LedgerService, mirroring ProfitAndLossReportTest's own helper — the dashboard's P&L widgets only READ ProfitAndLossReport, so their fixtures need real journal_lines underneath. */
    private function postEntry(Account $debitAccount, Account $creditAccount, int $amount, string $date, int $sourceId, string $sourceType = 'manual_journal'): JournalEntry
    {
        return DB::transaction(fn () => app(LedgerService::class)->post(new EntryDraft(
            entryDate: CarbonImmutable::parse($date),
            memo: 'Dashboard test entry',
            sourceType: $sourceType,
            sourceId: $sourceId,
            lines: [
                new LineDraft(accountId: $debitAccount->id, debit: $amount, credit: 0),
                new LineDraft(accountId: $creditAccount->id, debit: 0, credit: $amount),
            ],
        )));
    }

    /**
     * Dr AR (with contact_id — control accounts require it), Cr income — the
     * P1 shape InvoiceService::send() posts, in BASE-currency minor units
     * (P1fx converts a foreign invoice to base BEFORE posting, so the ledger
     * only ever holds base). Mirrors AgingReportTest::postInvoiceEntry().
     */
    private function postArEntry(Account $ar, Account $income, Invoice $invoice, int $baseAmount, string $date): void
    {
        DB::transaction(fn () => app(LedgerService::class)->post(new EntryDraft(
            entryDate: CarbonImmutable::parse($date),
            memo: 'Invoice AR',
            sourceType: 'invoice',
            sourceId: $invoice->id,
            lines: [
                new LineDraft(accountId: $ar->id, debit: $baseAmount, credit: 0, contactId: $invoice->contact_id),
                new LineDraft(accountId: $income->id, debit: 0, credit: $baseAmount),
            ],
        )));
    }

    public function test_dashboard_pl_snapshot_matches_profit_and_loss_report_for_the_fiscal_year_to_date(): void
    {
        $company = Company::factory()->create(); // fiscal_year_start_month defaults to 1 (calendar year)
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);

        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);

        $today = CarbonImmutable::today();
        $fiscalYearStart = $today->startOfYear();

        // Inside the fiscal year to date -> counted.
        $this->postEntry($bank, $income, 50000, $fiscalYearStart->addDays(2)->toDateString(), 1, 'invoice');
        $this->postEntry($expense, $bank, 20000, $today->toDateString(), 2, 'expense');
        // Before the fiscal year -> must NOT count.
        $this->postEntry($bank, $income, 999999, $fiscalYearStart->subDay()->toDateString(), 3, 'invoice');

        $expected = app(ProfitAndLossReport::class)->build($fiscalYearStart, $today);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pl_snapshot.from', $expected['from'])
                ->where('pl_snapshot.to', $expected['to'])
                ->where('pl_snapshot.income', $expected['income']['total'])
                ->where('pl_snapshot.expenses', $expected['expenses']['total'])
                ->where('pl_snapshot.net_profit', $expected['net_profit'])
                ->where('pl_snapshot.net_profit', 50000 - 20000)
            );
    }

    public function test_dashboard_income_expense_chart_matches_the_last_six_months_of_profit_and_loss(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);

        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);

        $today = CarbonImmutable::today();
        $chartFrom = $today->subMonths(5)->startOfMonth();

        $this->postEntry($bank, $income, 15000, $chartFrom->addDays(3)->toDateString(), 1, 'invoice');
        $this->postEntry($expense, $bank, 6000, $today->toDateString(), 2, 'expense');

        $expected = app(ProfitAndLossReport::class)->build($chartFrom, $today);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('income_expense_chart.labels', array_map(fn (array $m): string => $m['label'], $expected['months']))
                ->where('income_expense_chart.income', $expected['income']['total_by_month'])
                ->where('income_expense_chart.expenses', $expected['expenses']['total_by_month'])
            );
    }

    // ---------------------------------------------------------------
    // Task 7: recent activity, gated on audit.view.
    // ---------------------------------------------------------------

    public function test_dashboard_recent_activity_is_humanized_for_a_user_with_audit_view(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner); // Owner holds audit.view
        app(CompanyContext::class)->set($company);

        $this->post('/contacts', ['name' => 'Acme Corp', 'type' => ContactType::Customer->value])
            ->assertRedirect();

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('recent_activity', 1)
                ->where('recent_activity.0.action', 'Contact created')
                ->where('recent_activity.0.subject', 'Contact #'.Contact::query()->firstOrFail()->id)
            );
    }

    public function test_dashboard_hides_recent_activity_for_a_user_without_audit_view(): void
    {
        $company = Company::factory()->create();

        // An Owner performs (and thus logs) a real audited action first.
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $this->post('/contacts', ['name' => 'Acme Corp', 'type' => ContactType::Customer->value])
            ->assertRedirect();
        $this->assertGreaterThan(0, ActivityLog::query()->count());

        // Staff (config/permissions.php) lacks audit.view — the widget must
        // come back empty even though rows exist for the company.
        $this->actingAsRole($company, Role::Staff);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('recent_activity', []));
    }

    // ---------------------------------------------------------------
    // `can` ability map — what the sidebar nav gates on
    // ---------------------------------------------------------------

    /**
     * NAV_ABILITIES keys are permission strings that themselves contain dots
     * (e.g. "contacts.manage"), so they can't be reached with Inertia's
     * dot-path `where('can.contacts.manage', …)` — that would look for a
     * *nested* can.contacts.manage, not a "can" map with a literal
     * "contacts.manage" key. Assert the whole map with a callback instead.
     */
    private function assertCan(AssertableInertia $page, array $expected): void
    {
        $page->where('can', function ($can) use ($expected) {
            $can = is_array($can) ? $can : $can->all();
            foreach ($expected as $ability => $value) {
                $this->assertSame(
                    $value,
                    $can[$ability] ?? null,
                    "can.{$ability} expected ".var_export($value, true)
                );
            }

            return true;
        });
    }

    public function test_owner_dashboard_shares_the_full_nav_ability_set(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $this->assertCan($page, [
                    'contacts.manage' => true,
                    'items.manage' => true,
                    'accounts.manage' => true,
                    'ledger.post_manual' => true,
                    'bank_accounts.manage' => true,
                    'reports.view' => true,
                    'tax_rates.manage' => true,
                    'periods.lock' => true,
                    'settings.manage' => true,
                    'audit.view' => true,
                ]);
            });
    }

    public function test_staff_dashboard_grants_none_of_the_abilities_it_lacks(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        // Staff (config/permissions.php) holds none of the nav-gating
        // abilities at all — every one of these must come back false.
        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $this->assertCan($page, [
                    'contacts.manage' => false,
                    'items.manage' => false,
                    'accounts.manage' => false,
                    'ledger.post_manual' => false,
                    'bank_accounts.manage' => false,
                    'reports.view' => false,
                    'tax_rates.manage' => false,
                    'periods.lock' => false,
                    'settings.manage' => false,
                    'audit.view' => false,
                ]);
            });
    }

    public function test_admin_dashboard_reflects_the_owner_accountant_ability_split(): void
    {
        // Admin (config/permissions.php) lacks ledger.post_manual, periods.lock,
        // and audit.view — the one gap PROJECT_MAP §10 flags as worth
        // documenting. The nav's `can` map must reflect that split faithfully.
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Admin);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $this->assertCan($page, [
                    'contacts.manage' => true,
                    'accounts.manage' => true,
                    'reports.view' => true,
                    'settings.manage' => true,
                    'ledger.post_manual' => false,
                    'periods.lock' => false,
                    'audit.view' => false,
                ]);
            });
    }
}
