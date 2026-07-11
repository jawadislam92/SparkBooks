<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BillStatus;
use App\Enums\ExpenseStatus;
use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Reconciliation;
use App\Models\User;
use App\Support\CompanyContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase 6 Task 5 (spec §17.5): the month-end close checklist — process UI
 * over existing machinery (T1 reconciliations, draft documents, T2's
 * IntegrityChecker/integrity_runs, the period lock). No new accounting
 * logic is under test here; every step's "done" computation is a query
 * against tables already proven correct by their own task's tests
 * (ReconciliationTest, IntegrityCheckerTest, PeriodLockTest) — this file
 * proves the CHECKLIST reads those tables correctly, gates the right
 * ability, stays scoped per company, and that completing the close is
 * audit-logged.
 */
class MonthEndCloseTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function actingAsRole(Company $company, Role $role): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($company, ['role' => $role->value]);
        $user->forceFill(['current_company_id' => $company->id])->save();
        $this->actingAs($user);

        return $user;
    }

    /** Every scoped-model factory create below is wrapped in this — several (BankAccount, Invoice, Bill) have nested factories (linked ledger Account / Contact) that need an active CompanyContext to resolve their own company_id. */
    private function withCompany(Company $company, Closure $callback): mixed
    {
        return app(CompanyContext::class)->runFor($company, $callback);
    }

    private function bankAccount(Company $company): BankAccount
    {
        return $this->withCompany($company, fn () => BankAccount::factory()->create([
            'company_id' => $company->id,
        ]));
    }

    private function reconciliation(Company $company, BankAccount $bank, string $statementDate, string $status = 'completed'): Reconciliation
    {
        return $this->withCompany($company, fn () => Reconciliation::factory()->create([
            'company_id' => $company->id,
            'bank_account_id' => $bank->id,
            'statement_date' => $statementDate,
            'status' => $status,
        ]));
    }

    private function draftInvoice(Company $company, string $issueDate): Invoice
    {
        return $this->withCompany($company, fn () => Invoice::factory()->create([
            'company_id' => $company->id,
            'status' => InvoiceStatus::Draft->value,
            'issue_date' => $issueDate,
        ]));
    }

    private function draftBill(Company $company, string $billDate): Bill
    {
        return $this->withCompany($company, fn () => Bill::factory()->create([
            'company_id' => $company->id,
            'status' => BillStatus::Draft->value,
            'bill_date' => $billDate,
        ]));
    }

    private function draftExpense(Company $company, string $expenseDate): Expense
    {
        return $this->withCompany($company, fn () => Expense::factory()->create([
            'company_id' => $company->id,
            'status' => ExpenseStatus::Draft->value,
            'expense_date' => $expenseDate,
        ]));
    }

    /** A plain ledger (chart-of-accounts) account, for posting manual journals directly — distinct from bankAccount() above, which creates the BankAccount (T1 reconciliation) row. */
    private function chartAccount(Company $company, string $code, AccountType $type, AccountSubtype $subtype): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => $code,
            'type' => $type->value,
            'subtype' => $subtype->value,
        ]);
    }

    private function postManualJournal(string $entryDate, int $debitAccountId, int $creditAccountId, int $amount): void
    {
        $this->postJson('/journal-entries', [
            'entry_date' => $entryDate,
            'memo' => 'Test entry',
            'lines' => [
                ['account_id' => $debitAccountId, 'debit' => $amount, 'credit' => 0],
                ['account_id' => $creditAccountId, 'debit' => 0, 'credit' => $amount],
            ],
        ], ['X-Idempotency-Key' => (string) Str::uuid()])->assertRedirect();
    }

    // ---------------------------------------------------------------
    // Step 1: reconcile
    // ---------------------------------------------------------------

    public function test_reconcile_step_is_not_done_while_any_active_bank_account_is_missing_a_completed_reconciliation(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $reconciled = $this->bankAccount($company);
        $outstanding = $this->bankAccount($company);
        $this->reconciliation($company, $reconciled, '2026-06-30');
        // $outstanding has no reconciliation at all.

        $this->get('/close?month_end=2026-06-30')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('steps.reconcile.done', false)
                ->where('steps.reconcile.outstanding_accounts.0.id', $outstanding->id)
            );
    }

    public function test_reconcile_step_ignores_a_reconciliation_dated_before_the_month_end(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $bank = $this->bankAccount($company);
        $this->reconciliation($company, $bank, '2026-05-31'); // before the month end below

        $this->get('/close?month_end=2026-06-30')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('steps.reconcile.done', false));
    }

    public function test_reconcile_step_is_done_once_every_active_bank_account_is_reconciled_through_month_end(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $bankA = $this->bankAccount($company);
        $bankB = $this->bankAccount($company);
        $this->reconciliation($company, $bankA, '2026-06-30');
        $this->reconciliation($company, $bankB, '2026-07-05'); // after month end also counts

        $this->get('/close?month_end=2026-06-30')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('steps.reconcile.done', true)
                ->where('steps.reconcile.outstanding_accounts', [])
            );
    }

    public function test_reconcile_step_ignores_archived_bank_accounts(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $archived = $this->bankAccount($company);
        $archived->update(['is_archived' => true]);
        // No reconciliation for the archived account, and no OTHER active
        // account exists — done should still be true (vacuous truth over
        // the active set), proving the archived account isn't counted.

        $this->get('/close?month_end=2026-06-30')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('steps.reconcile.done', true));
    }

    // ---------------------------------------------------------------
    // Step 4: drafts sweep
    // ---------------------------------------------------------------

    public function test_drafts_step_lists_a_back_dated_draft_invoice_and_clears_once_it_is_sent(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $invoice = $this->draftInvoice($company, '2026-06-15');

        $this->get('/close?month_end=2026-06-30')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('steps.drafts.done', false)
                ->where('steps.drafts.drafts.0.type', 'invoice')
                ->where('steps.drafts.drafts.0.id', $invoice->id)
                ->where('steps.drafts.drafts.0.date', '2026-06-15')
            );

        $invoice->forceFill(['status' => InvoiceStatus::Sent->value])->save();

        $this->get('/close?month_end=2026-06-30')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('steps.drafts.done', true)
                ->where('steps.drafts.drafts', [])
            );
    }

    public function test_drafts_step_lists_a_back_dated_draft_bill_and_clears_once_deleted(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $bill = $this->draftBill($company, '2026-06-20');

        $this->get('/close?month_end=2026-06-30')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('steps.drafts.done', false)
                ->where('steps.drafts.drafts.0.type', 'bill')
                ->where('steps.drafts.drafts.0.id', $bill->id)
            );

        $bill->delete();

        $this->get('/close?month_end=2026-06-30')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('steps.drafts.done', true));
    }

    public function test_drafts_step_lists_a_back_dated_draft_expense(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $expense = $this->draftExpense($company, '2026-06-01');

        $this->get('/close?month_end=2026-06-30')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('steps.drafts.done', false)
                ->where('steps.drafts.drafts.0.type', 'expense')
                ->where('steps.drafts.drafts.0.id', $expense->id)
            );
    }

    public function test_drafts_step_ignores_drafts_dated_after_the_month_end(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->draftInvoice($company, '2026-07-01'); // after the June 30 month end

        $this->get('/close?month_end=2026-06-30')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('steps.drafts.done', true)
                ->where('steps.drafts.drafts', [])
            );
    }

    public function test_manual_journals_never_appear_in_the_drafts_sweep_because_they_have_no_draft_state(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $bank = $this->chartAccount($company, '1010', AccountType::Asset, AccountSubtype::Bank);
        $income = $this->chartAccount($company, '4000', AccountType::Income, AccountSubtype::OperatingIncome);
        $this->postManualJournal('2026-06-01', $bank->id, $income->id, 10000);

        // The manual journal above is dated well before the month end and is
        // immediately posted (this system has no "save as draft" step for
        // manual journals — see MonthEndCloseService::draftsStep's docblock)
        // — it must never show up in the sweep.
        $this->get('/close?month_end=2026-06-30')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('steps.drafts.done', true)
                ->where('steps.drafts.drafts', [])
            );
    }

    // ---------------------------------------------------------------
    // Step 5: integrity checker
    // ---------------------------------------------------------------

    public function test_integrity_step_is_done_after_a_clean_run(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->postJson('/close/integrity-check', ['month_end' => '2026-06-30'])->assertRedirect();

        $this->get('/close?month_end=2026-06-30')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('steps.integrity.done', true));
    }

    public function test_integrity_step_is_not_done_and_shows_findings_after_a_seeded_corruption(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $bank = $this->chartAccount($company, '1010', AccountType::Asset, AccountSubtype::Bank);
        $income = $this->chartAccount($company, '4000', AccountType::Income, AccountSubtype::OperatingIncome);
        $this->postManualJournal('2026-06-01', $bank->id, $income->id, 10000);

        $entry = JournalEntry::query()->where('source_type', 'manual_journal')->latest('id')->firstOrFail();
        // Raw-tamper one line so the entry no longer balances — bypasses
        // LedgerService/model guards entirely (mirrors IntegrityCheckerTest's
        // own rawInsertLine-style corruption seeding), since the normal write
        // path would simply refuse to create this state.
        DB::table('journal_lines')
            ->where('journal_entry_id', $entry->id)
            ->where('account_id', $bank->id)
            ->update(['debit' => 20000]);

        $this->postJson('/close/integrity-check', ['month_end' => '2026-06-30'])->assertRedirect();

        $this->get('/close?month_end=2026-06-30')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('steps.integrity.done', false)
                ->has('steps.integrity.findings.0.check')
            );
    }

    // ---------------------------------------------------------------
    // Step 6: lock
    // ---------------------------------------------------------------

    public function test_lock_step_reflects_the_real_period_lock_and_the_lock_action_sets_it(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/close?month_end=2026-06-30')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('steps.lock.done', false));

        $this->postJson('/close/lock', ['month_end' => '2026-06-30'])->assertRedirect();

        $this->assertSame('2026-06-30', $company->fresh()->books_locked_through->toDateString());

        $this->get('/close?month_end=2026-06-30')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('steps.lock.done', true)
                ->where('steps.lock.locked_through', '2026-06-30')
            );

        $audit = ActivityLog::query()->where('action', 'periods.lock_moved')->sole();
        $this->assertSame(['books_locked_through' => null], $audit->before);
        $this->assertSame(['books_locked_through' => '2026-06-30'], $audit->after);
    }

    public function test_lock_step_is_movable_and_flips_back_to_not_done(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->postJson('/close/lock', ['month_end' => '2026-06-30'])->assertRedirect();
        $this->postJson('/close/lock', ['month_end' => '2026-05-31'])->assertRedirect();

        $this->assertSame('2026-05-31', $company->fresh()->books_locked_through->toDateString());

        $this->get('/close?month_end=2026-06-30')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('steps.lock.done', false));
    }

    public function test_lock_action_rejects_a_future_month_end(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $response = $this->postJson('/close/lock', ['month_end' => '2099-01-31']);

        $response->assertSessionHasErrors('month_end');
        $this->assertNull($company->fresh()->books_locked_through);
    }

    // ---------------------------------------------------------------
    // Manual-acknowledge steps (2/3) — revisitable
    // ---------------------------------------------------------------

    public function test_ar_ap_aging_and_trial_balance_steps_are_acknowledged_and_revisitable(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->postJson('/close/acknowledge', [
            'month_end' => '2026-06-30', 'step' => 'ar_ap_aging', 'acknowledged' => true,
        ])->assertRedirect();
        $this->postJson('/close/acknowledge', [
            'month_end' => '2026-06-30', 'step' => 'trial_balance', 'acknowledged' => true,
        ])->assertRedirect();

        $this->get('/close?month_end=2026-06-30')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('steps.ar_ap_aging.done', true)
                ->where('steps.trial_balance.done', true)
            );

        // Revisit: un-acknowledge the aging review.
        $this->postJson('/close/acknowledge', [
            'month_end' => '2026-06-30', 'step' => 'ar_ap_aging', 'acknowledged' => false,
        ])->assertRedirect();

        $this->get('/close?month_end=2026-06-30')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('steps.ar_ap_aging.done', false)
                ->where('steps.trial_balance.done', true)
            );
    }

    public function test_acknowledging_a_step_is_audit_logged(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->postJson('/close/acknowledge', [
            'month_end' => '2026-06-30', 'step' => 'ar_ap_aging', 'acknowledged' => true,
        ])->assertRedirect();

        $audit = ActivityLog::query()->where('action', 'month_end_close.step_acknowledged')->sole();
        $this->assertSame('ar_ap_aging', $audit->after['step']);
        $this->assertNotNull($audit->after['ar_ap_reviewed_at']);
    }

    // ---------------------------------------------------------------
    // Completing the close
    // ---------------------------------------------------------------

    public function test_completing_the_close_writes_an_audit_row(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->postJson('/close/lock', ['month_end' => '2026-06-30'])->assertRedirect();
        $this->postJson('/close/complete', ['month_end' => '2026-06-30'])->assertRedirect();

        $audit = ActivityLog::query()->where('action', 'period.closed')->sole();
        $this->assertSame(['month_end' => '2026-06-30'], $audit->after);
    }

    public function test_completing_the_close_requires_the_period_to_already_be_locked(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $response = $this->postJson('/close/complete', ['month_end' => '2026-06-30']);

        $response->assertSessionHasErrors('month_end');
        $this->assertSame(0, ActivityLog::query()->where('action', 'period.closed')->count());
    }

    public function test_completing_the_close_is_revisitable(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->postJson('/close/lock', ['month_end' => '2026-06-30'])->assertRedirect();
        $this->postJson('/close/complete', ['month_end' => '2026-06-30'])->assertRedirect();
        $this->postJson('/close/complete', ['month_end' => '2026-06-30'])->assertRedirect();

        $this->assertSame(2, ActivityLog::query()->where('action', 'period.closed')->count());
    }

    // ---------------------------------------------------------------
    // Gate matrix — periods.lock (Owner/Accountant only, per config/permissions.php)
    // ---------------------------------------------------------------

    public function test_owner_can_view_the_close_checklist(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/close?month_end=2026-06-30')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Close/Index')
                ->where('monthEnd', '2026-06-30')
                ->has('steps.reconcile')
                ->has('steps.ar_ap_aging')
                ->has('steps.trial_balance')
                ->has('steps.drafts')
                ->has('steps.integrity')
                ->has('steps.lock')
                ->has('steps.export')
            );
    }

    public function test_accountant_can_view_the_close_checklist(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Accountant);

        $this->get('/close?month_end=2026-06-30')->assertOk();
    }

    public function test_admin_cannot_view_the_close_checklist(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Admin);

        $this->get('/close?month_end=2026-06-30')->assertForbidden();
    }

    public function test_staff_cannot_view_the_close_checklist(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->get('/close?month_end=2026-06-30')->assertForbidden();
    }

    public function test_staff_cannot_perform_any_close_action(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->postJson('/close/acknowledge', ['month_end' => '2026-06-30', 'step' => 'ar_ap_aging', 'acknowledged' => true])
            ->assertForbidden();
        $this->postJson('/close/integrity-check', ['month_end' => '2026-06-30'])->assertForbidden();
        $this->postJson('/close/lock', ['month_end' => '2026-06-30'])->assertForbidden();
        $this->postJson('/close/complete', ['month_end' => '2026-06-30'])->assertForbidden();
    }

    // ---------------------------------------------------------------
    // Scoping — another company's state must never bleed in
    // ---------------------------------------------------------------

    public function test_close_checklist_is_scoped_to_the_current_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create(['books_locked_through' => '2026-06-30']);
        $this->actingAsRole($companyA, Role::Owner);

        // Company B has its own fully-reconciled bank account and a locked
        // period; Company A has neither. None of it should leak into A's
        // checklist.
        $bankB = $this->bankAccount($companyB);
        $this->reconciliation($companyB, $bankB, '2026-06-30');
        $this->draftInvoice($companyB, '2026-06-01');

        $this->get('/close?month_end=2026-06-30')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('steps.lock.done', false)
                ->where('steps.drafts.done', true)
                ->where('steps.drafts.drafts', [])
            );
    }
}
