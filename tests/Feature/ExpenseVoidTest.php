<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\EntryRole;
use App\Enums\ExpenseStatus;
use App\Enums\Role;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\JournalEntry;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

/**
 * Phase 4 Task 6: voiding a recorded expense — posting rule P9 (spec §6), a
 * mirror reversal of the expense's own P4/P5 entry, via ExpenseService::void.
 * Mirrors InvoiceVoidTest's own shape (P3-T5) — every scenario here drives
 * the REAL record() HTTP flow (never a hand-rolled Recorded row) because
 * void() needs a genuine primary JournalEntry on the books to reverse.
 */
class ExpenseVoidTest extends TestCase
{
    use AssertsLedgerBalanced;
    use RefreshDatabase;

    private function actingAsRole(Company $company, Role $role): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($company, ['role' => $role->value]);
        $user->forceFill(['current_company_id' => $company->id])->save();
        $this->actingAs($user);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function categoryAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
        ], $overrides));
    }

    private function taxPayableAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '2100',
            'name' => 'Tax Payable',
            'type' => AccountType::Liability->value,
            'subtype' => AccountSubtype::Tax->value,
            'is_system' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function bankAccount(Company $company, array $overrides = []): BankAccount
    {
        return app(CompanyContext::class)->runFor($company, fn () => BankAccount::factory()->create(array_merge([
            'company_id' => $company->id,
        ], $overrides)));
    }

    /** @param array<string, mixed> $overrides */
    private function draftExpense(Company $company, array $overrides = []): Expense
    {
        return Expense::factory()->create(array_merge([
            'company_id' => $company->id,
            'status' => ExpenseStatus::Draft->value,
        ], $overrides));
    }

    /**
     * Wrapped in CompanyContext::runFor — see ExpenseRecordTest::expenseLine
     * for why (ExpenseLine::booted()'s freeze guard needs a live CompanyContext).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function expenseLine(Expense $expense, Account $categoryAccount, array $overrides = []): ExpenseLine
    {
        $company = Company::query()->findOrFail($expense->company_id);

        return app(CompanyContext::class)->runFor($company, fn () => ExpenseLine::factory()->create(array_merge([
            'expense_id' => $expense->id,
            'company_id' => $expense->company_id,
            'category_account_id' => $categoryAccount->id,
        ], $overrides)));
    }

    private function recordExpenseHttp(Expense $expense): TestResponse
    {
        return $this->postJson("/expenses/{$expense->id}/record", [], [
            'X-Idempotency-Key' => (string) Str::uuid(),
        ]);
    }

    private function voidExpenseHttp(Expense $expense, ?string $key = null): TestResponse
    {
        return $this->postJson("/expenses/{$expense->id}/void", [], [
            'X-Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    /**
     * Builds a genuine RECORDED expense (real draft -> record flow) with one
     * taxed line: 10000 net + 18% (1800) tax = 11800 total.
     */
    private function recordedTaxedExpense(Company $company, Account $category, BankAccount $bank): Expense
    {
        $taxRate = TaxRate::factory()->create(['company_id' => $company->id, 'rate' => '18.0000']);

        $expense = $this->draftExpense($company, ['bank_account_id' => $bank->id, 'expense_date' => '2026-07-01']);
        $this->expenseLine($expense, $category, ['amount' => 10000, 'tax_rate_id' => $taxRate->id]);
        $this->recordExpenseHttp($expense)->assertRedirect();

        return $expense->fresh();
    }

    // ---------------------------------------------------------------
    // P9: void a recorded, untaxed (P4) expense — reverses exactly
    // ---------------------------------------------------------------

    public function test_void_posts_p9_reversal_exactly_for_an_untaxed_p4_expense(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $category = $this->categoryAccount($company);

        $expense = $this->draftExpense($company, ['bank_account_id' => $bank->id, 'expense_date' => '2026-07-01']);
        $this->expenseLine($expense, $category, ['amount' => 30000, 'tax_rate_id' => null]);
        $this->recordExpenseHttp($expense)->assertRedirect();
        $expense = $expense->fresh();

        $primaryEntry = JournalEntry::query()
            ->where('source_type', 'expense')->where('source_id', $expense->id)
            ->where('entry_role', EntryRole::Primary->value)->sole();

        $response = $this->voidExpenseHttp($expense);
        $response->assertRedirect(route('expenses.show', $expense->id));

        $expense->refresh();
        $this->assertSame(ExpenseStatus::Void, $expense->status);
        $this->assertNotNull($expense->voided_at);

        $reversal = JournalEntry::query()
            ->where('source_type', 'expense')->where('source_id', $expense->id)
            ->where('entry_role', EntryRole::Reversal->value)->sole();

        $this->assertSame($primaryEntry->id, $reversal->reverses_entry_id);
        $this->assertSame(CarbonImmutable::today()->toDateString(), $reversal->entry_date->toDateString());
        $this->assertCount(2, $reversal->lines);

        $categoryLine = $reversal->lines->firstWhere('account_id', $category->id);
        $this->assertSame(0, $categoryLine->debit);
        $this->assertSame(30000, $categoryLine->credit);

        $bankLine = $reversal->lines->firstWhere('account_id', $bank->ledger_account_id);
        $this->assertSame(30000, $bankLine->debit);
        $this->assertSame(0, $bankLine->credit);

        $this->assertSame($reversal->lines->sum('debit'), $reversal->lines->sum('credit'));
        $this->assertLedgerBalances();

        $audit = ActivityLog::query()->where('action', 'expense.voided')->sole();
        $this->assertSame($expense->id, $audit->subject_id);
        $this->assertSame($reversal->id, $audit->after['reversal_entry_id']);
    }

    // ---------------------------------------------------------------
    // P9: void a recorded, taxed (P5) expense — reverses exactly, incl. tax
    // ---------------------------------------------------------------

    public function test_void_posts_p9_reversal_exactly_for_a_taxed_p5_expense(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $category = $this->categoryAccount($company);
        $taxPayable = $this->taxPayableAccount($company);

        $expense = $this->recordedTaxedExpense($company, $category, $bank);

        $reversal = null;
        $response = $this->voidExpenseHttp($expense);
        $response->assertRedirect();

        $expense->refresh();
        $this->assertSame(ExpenseStatus::Void, $expense->status);

        $reversal = JournalEntry::query()
            ->where('source_type', 'expense')->where('source_id', $expense->id)
            ->where('entry_role', EntryRole::Reversal->value)->sole();
        $this->assertCount(3, $reversal->lines);

        $categoryLine = $reversal->lines->firstWhere('account_id', $category->id);
        $this->assertSame(0, $categoryLine->debit);
        $this->assertSame(10000, $categoryLine->credit);

        // Input tax was a DEBIT on P5 — the reversal credits it back.
        $taxLine = $reversal->lines->firstWhere('account_id', $taxPayable->id);
        $this->assertSame(0, $taxLine->debit);
        $this->assertSame(1800, $taxLine->credit);

        $bankLine = $reversal->lines->firstWhere('account_id', $bank->ledger_account_id);
        $this->assertSame(11800, $bankLine->debit);
        $this->assertSame(0, $bankLine->credit);

        $this->assertSame($reversal->lines->sum('debit'), $reversal->lines->sum('credit'));
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Guards: draft rejected, already-void rejected
    // ---------------------------------------------------------------

    public function test_voiding_a_draft_expense_is_rejected_and_posts_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $category = $this->categoryAccount($company);

        $expense = $this->draftExpense($company, ['bank_account_id' => $bank->id]);
        $this->expenseLine($expense, $category, ['amount' => 10000, 'tax_rate_id' => null]);

        $response = $this->voidExpenseHttp($expense);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'expense')->count());
        $this->assertSame(ExpenseStatus::Draft, $expense->fresh()->status);
        $this->assertLedgerBalances();
    }

    public function test_second_void_of_the_same_expense_is_rejected_with_a_friendly_422(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $category = $this->categoryAccount($company);

        $expense = $this->draftExpense($company, ['bank_account_id' => $bank->id]);
        $this->expenseLine($expense, $category, ['amount' => 10000, 'tax_rate_id' => null]);
        $this->recordExpenseHttp($expense)->assertRedirect();
        $expense = $expense->fresh();

        $this->voidExpenseHttp($expense)->assertRedirect();

        $response = $this->voidExpenseHttp($expense->fresh());

        $response->assertSessionHasErrors('status');
        $this->assertStringContainsString('already been voided', session('errors')->first('status'));

        $this->assertSame(1, JournalEntry::query()
            ->where('source_type', 'expense')->where('source_id', $expense->id)
            ->where('entry_role', EntryRole::Reversal->value)->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Idempotency
    // ---------------------------------------------------------------

    public function test_idempotent_double_submit_void_posts_exactly_one_reversal(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $category = $this->categoryAccount($company);
        $key = (string) Str::uuid();

        $expense = $this->draftExpense($company, ['bank_account_id' => $bank->id]);
        $this->expenseLine($expense, $category, ['amount' => 10000, 'tax_rate_id' => null]);
        $this->recordExpenseHttp($expense)->assertRedirect();
        $expense = $expense->fresh();

        $first = $this->voidExpenseHttp($expense, $key);
        $second = $this->voidExpenseHttp($expense, $key);

        $first->assertRedirect();
        $second->assertRedirect();

        $this->assertSame(1, JournalEntry::query()
            ->where('source_type', 'expense')->where('source_id', $expense->id)
            ->where('entry_role', EntryRole::Reversal->value)->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'expense.voided')->count());
        $this->assertLedgerBalances();
    }

    public function test_a_missing_idempotency_key_returns_422_and_posts_nothing_on_void(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $category = $this->categoryAccount($company);

        $expense = $this->draftExpense($company, ['bank_account_id' => $bank->id]);
        $this->expenseLine($expense, $category, ['amount' => 10000, 'tax_rate_id' => null]);
        $this->recordExpenseHttp($expense)->assertRedirect();
        $expense = $expense->fresh();

        $response = $this->postJson("/expenses/{$expense->id}/void");

        $response->assertSessionHasErrors('idempotency_key');
        $this->assertSame(ExpenseStatus::Recorded, $expense->fresh()->status);
    }

    // ---------------------------------------------------------------
    // Locked period: reversal (dated today) 422s when today is locked
    // ---------------------------------------------------------------

    public function test_voiding_into_a_locked_period_returns_422_and_posts_nothing(): void
    {
        $company = Company::factory()->create();
        $user = $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $category = $this->categoryAccount($company);

        $expense = $this->draftExpense($company, ['bank_account_id' => $bank->id]);
        $this->expenseLine($expense, $category, ['amount' => 10000, 'tax_rate_id' => null]);
        $this->recordExpenseHttp($expense)->assertRedirect();
        $expense = $expense->fresh();

        // Lock through today — the reversal is always dated today (void()
        // takes no date parameter), so this must reject it.
        $company->forceFill(['books_locked_through' => CarbonImmutable::today()->toDateString()])->save();
        // SetCompanyContext resolves the current company off $user->currentCompany,
        // cached by Eloquent on the (shared, actingAs'd) $user object — the
        // save() just above mutated a DIFFERENT Company instance, so without
        // unsetting the cached relation the next request would still see the
        // pre-lock row. Mirrors InvoiceVoidTest's identical locked-period tests.
        $user->unsetRelation('currentCompany');

        $response = $this->voidExpenseHttp($expense);

        $response->assertSessionHasErrors('status');
        $this->assertStringContainsString('locked', session('errors')->first('status'));

        $expense->refresh();
        $this->assertSame(ExpenseStatus::Recorded, $expense->status);
        $this->assertSame(0, JournalEntry::query()
            ->where('source_type', 'expense')->where('source_id', $expense->id)
            ->where('entry_role', EntryRole::Reversal->value)->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Gate: expenses.void — Owner/Admin/Accountant ok, Staff forbidden
    // ---------------------------------------------------------------

    public function test_owner_admin_and_accountant_can_void_an_expense(): void
    {
        foreach ([Role::Owner, Role::Admin, Role::Accountant] as $role) {
            $company = Company::factory()->create();
            $this->actingAsRole($company, $role);
            $bank = $this->bankAccount($company);
            $category = $this->categoryAccount($company);

            $expense = $this->draftExpense($company, ['bank_account_id' => $bank->id]);
            $this->expenseLine($expense, $category, ['amount' => 10000, 'tax_rate_id' => null]);
            $this->recordExpenseHttp($expense)->assertRedirect();

            $this->voidExpenseHttp($expense->fresh())->assertRedirect();
        }
    }

    public function test_staff_is_forbidden_from_voiding_an_expense(): void
    {
        $company = Company::factory()->create();
        $bank = $this->bankAccount($company);
        $category = $this->categoryAccount($company);

        $owner = User::factory()->create();
        $owner->companies()->attach($company, ['role' => Role::Owner->value]);
        $owner->forceFill(['current_company_id' => $company->id])->save();
        $this->actingAs($owner);

        $expense = $this->draftExpense($company, ['bank_account_id' => $bank->id]);
        $this->expenseLine($expense, $category, ['amount' => 10000, 'tax_rate_id' => null]);
        $this->recordExpenseHttp($expense)->assertRedirect();
        $expense = $expense->fresh();

        $this->actingAsRole($company, Role::Staff);

        $this->voidExpenseHttp($expense)->assertForbidden();
        $this->assertSame(ExpenseStatus::Recorded, $expense->fresh()->status);
    }

    // ---------------------------------------------------------------
    // Scoped 404
    // ---------------------------------------------------------------

    public function test_a_foreign_expense_404s_on_void(): void
    {
        $companyA = Company::factory()->create();
        $bankA = $this->bankAccount($companyA);
        $categoryA = $this->categoryAccount($companyA);

        $ownerA = User::factory()->create();
        $ownerA->companies()->attach($companyA, ['role' => Role::Owner->value]);
        $ownerA->forceFill(['current_company_id' => $companyA->id])->save();
        $this->actingAs($ownerA);

        $foreignExpense = $this->draftExpense($companyA, ['bank_account_id' => $bankA->id]);
        $this->expenseLine($foreignExpense, $categoryA, ['amount' => 10000, 'tax_rate_id' => null]);
        $this->recordExpenseHttp($foreignExpense)->assertRedirect();
        $foreignExpense = $foreignExpense->fresh();

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $this->voidExpenseHttp($foreignExpense)->assertNotFound();
    }

    // ---------------------------------------------------------------
    // AP-independent sanity: the whole-ledger invariant holds even after
    // a full record -> void round trip nets the affected accounts to zero.
    // ---------------------------------------------------------------

    public function test_a_full_record_then_void_round_trip_nets_every_touched_account_to_zero(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $category = $this->categoryAccount($company);
        $taxPayable = $this->taxPayableAccount($company);

        $expense = $this->recordedTaxedExpense($company, $category, $bank);
        $this->voidExpenseHttp($expense)->assertRedirect();

        foreach ([$category->id, $bank->ledger_account_id, $taxPayable->id] as $accountId) {
            $net = DB::table('journal_lines')
                ->where('account_id', $accountId)
                ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
                ->value('net');
            $this->assertSame(0, (int) $net, "Account {$accountId} did not net to zero after void.");
        }

        $this->assertLedgerBalances();
    }
}
