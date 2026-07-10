<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ExpenseStatus;
use App\Enums\Role;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\JournalEntry;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

class ExpenseRecordTest extends TestCase
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
    private function contact(Company $company, array $overrides = []): Contact
    {
        return Contact::factory()->create(array_merge(['company_id' => $company->id], $overrides));
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
        // BankAccountFactory nests a linked Account whose company_id comes
        // from the ambient CompanyContext (see its own doc comment), so this
        // must run inside runFor() — mirrors ExpenseDraftTest's own helper.
        return app(CompanyContext::class)->runFor($company, fn () => BankAccount::factory()->create(array_merge([
            'company_id' => $company->id,
        ], $overrides)));
    }

    /** @param array<string, mixed> $overrides */
    private function taxRate(Company $company, array $overrides = []): TaxRate
    {
        return TaxRate::factory()->create(array_merge(['company_id' => $company->id], $overrides));
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
     * Wrapped in CompanyContext::runFor: ExpenseLine::booted()'s freeze guard
     * re-queries `Expense::query()->find($line->expense_id)` on every
     * create, and that query applies BelongsToCompany's global scope — which
     * needs a live CompanyContext even though `company_id` itself is passed
     * explicitly here. Mirrors InvoiceSendTest::invoiceLine.
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

    private function recordExpense(Expense $expense, ?string $key = null): TestResponse
    {
        return $this->postJson("/expenses/{$expense->id}/record", [], [
            'X-Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    // ---------------------------------------------------------------
    // P4: untaxed multi-line — each Dr category, one Cr bank = total
    // ---------------------------------------------------------------

    public function test_record_posts_p4_exactly_for_an_untaxed_multi_line_expense(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $categoryA = $this->categoryAccount($company, ['code' => '5000']);
        $categoryB = $this->categoryAccount($company, ['code' => '5010']);

        $expense = $this->draftExpense($company, ['bank_account_id' => $bank->id, 'expense_date' => '2026-07-05']);
        $this->expenseLine($expense, $categoryA, ['amount' => 30000, 'tax_rate_id' => null]);
        $this->expenseLine($expense, $categoryB, ['amount' => 45000, 'tax_rate_id' => null]);

        $response = $this->recordExpense($expense);
        $response->assertRedirect(route('expenses.edit', $expense->id));

        $expense->refresh();
        $this->assertSame(ExpenseStatus::Recorded, $expense->status);
        $this->assertNotNull($expense->recorded_at);
        $this->assertSame(75000, $expense->subtotal);
        $this->assertSame(0, $expense->tax_total);
        $this->assertSame(75000, $expense->total);

        $entry = JournalEntry::query()->where('source_type', 'expense')->where('source_id', $expense->id)->sole();
        $this->assertCount(3, $entry->lines);

        $lineA = $entry->lines->firstWhere('account_id', $categoryA->id);
        $this->assertSame(30000, $lineA->debit);
        $this->assertSame(0, $lineA->credit);

        $lineB = $entry->lines->firstWhere('account_id', $categoryB->id);
        $this->assertSame(45000, $lineB->debit);
        $this->assertSame(0, $lineB->credit);

        $bankLine = $entry->lines->firstWhere('account_id', $bank->ledger_account_id);
        $this->assertSame(0, $bankLine->debit);
        $this->assertSame(75000, $bankLine->credit);

        $this->assertSame($entry->lines->sum('debit'), $entry->lines->sum('credit'));
        $this->assertLedgerBalances();

        $audit = ActivityLog::query()->where('action', 'expense.recorded')->sole();
        $this->assertSame($expense->id, $audit->subject_id);
        $this->assertSame($entry->id, $audit->after['entry_id']);
    }

    // ---------------------------------------------------------------
    // P5: the brief's own worked example — 10,000 net + 1,800 tax
    // ---------------------------------------------------------------

    public function test_record_posts_p5_exactly_for_the_spec_worked_example(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $category = $this->categoryAccount($company);
        $taxPayable = $this->taxPayableAccount($company);
        $eighteenPercent = $this->taxRate($company, ['rate' => '18.0000']);

        $expense = $this->draftExpense($company, ['bank_account_id' => $bank->id]);
        $this->expenseLine($expense, $category, ['amount' => 10000, 'tax_rate_id' => $eighteenPercent->id]);

        $this->recordExpense($expense)->assertRedirect();

        $expense->refresh();
        $this->assertSame(10000, $expense->subtotal);
        $this->assertSame(1800, $expense->tax_total);
        $this->assertSame(11800, $expense->total);

        $entry = JournalEntry::query()->where('source_type', 'expense')->where('source_id', $expense->id)->sole();
        $this->assertCount(3, $entry->lines);

        $categoryLine = $entry->lines->firstWhere('account_id', $category->id);
        $this->assertSame(10000, $categoryLine->debit);
        $this->assertSame(0, $categoryLine->credit);

        // INPUT TAX IS A DEBIT — it nets against output tax, not a liability credit.
        $taxLine = $entry->lines->firstWhere('account_id', $taxPayable->id);
        $this->assertSame(1800, $taxLine->debit);
        $this->assertSame(0, $taxLine->credit);

        $bankLine = $entry->lines->firstWhere('account_id', $bank->ledger_account_id);
        $this->assertSame(0, $bankLine->debit);
        $this->assertSame(11800, $bankLine->credit);

        $this->assertSame($entry->lines->sum('debit'), $entry->lines->sum('credit'));
        $this->assertLedgerBalances();
    }

    public function test_mixed_multi_line_taxed_and_untaxed_lines_post_correctly(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $categoryA = $this->categoryAccount($company, ['code' => '5000']);
        $categoryB = $this->categoryAccount($company, ['code' => '5010']);
        $eighteenPercent = $this->taxRate($company, ['rate' => '18.0000']);
        $this->taxPayableAccount($company);

        $expense = $this->draftExpense($company, ['bank_account_id' => $bank->id]);
        $this->expenseLine($expense, $categoryA, ['amount' => 10000, 'tax_rate_id' => $eighteenPercent->id]);
        $this->expenseLine($expense, $categoryB, ['amount' => 5000, 'tax_rate_id' => null]);

        $this->recordExpense($expense)->assertRedirect();

        $expense->refresh();
        $this->assertSame(15000, $expense->subtotal);
        $this->assertSame(1800, $expense->tax_total);
        $this->assertSame(16800, $expense->total);

        $entry = JournalEntry::query()->where('source_type', 'expense')->where('source_id', $expense->id)->sole();
        $this->assertCount(4, $entry->lines);
        $this->assertSame($entry->lines->sum('debit'), $entry->lines->sum('credit'));
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // P12/P13/P15: category account is itself a liability/equity/asset
    // ---------------------------------------------------------------

    public function test_p12_a_category_account_that_is_2100_tax_payable_posts_a_tax_remittance(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $taxPayable = $this->taxPayableAccount($company);

        $expense = $this->draftExpense($company, ['bank_account_id' => $bank->id]);
        $this->expenseLine($expense, $taxPayable, ['amount' => 5000, 'tax_rate_id' => null]);

        $this->recordExpense($expense)->assertRedirect();

        $entry = JournalEntry::query()->where('source_type', 'expense')->where('source_id', $expense->id)->sole();
        $this->assertCount(2, $entry->lines);

        $taxLine = $entry->lines->firstWhere('account_id', $taxPayable->id);
        $this->assertSame(5000, $taxLine->debit);
        $this->assertSame(0, $taxLine->credit);

        $bankLine = $entry->lines->firstWhere('account_id', $bank->ledger_account_id);
        $this->assertSame(5000, $bankLine->credit);

        $this->assertLedgerBalances();
    }

    public function test_p13_a_category_account_that_is_owner_drawings_equity_posts_correctly(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $drawings = $this->categoryAccount($company, [
            'code' => '3900',
            'type' => AccountType::Equity->value,
            'subtype' => AccountSubtype::Equity->value,
        ]);

        $expense = $this->draftExpense($company, ['bank_account_id' => $bank->id]);
        $this->expenseLine($expense, $drawings, ['amount' => 20000, 'tax_rate_id' => null]);

        $this->recordExpense($expense)->assertRedirect();

        $entry = JournalEntry::query()->where('source_type', 'expense')->where('source_id', $expense->id)->sole();
        $drawingsLine = $entry->lines->firstWhere('account_id', $drawings->id);
        $this->assertSame(20000, $drawingsLine->debit);
        $this->assertSame(0, $drawingsLine->credit);

        $bankLine = $entry->lines->firstWhere('account_id', $bank->ledger_account_id);
        $this->assertSame(20000, $bankLine->credit);

        $this->assertLedgerBalances();
    }

    public function test_p15_a_category_account_that_is_office_equipment_asset_posts_correctly(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $officeEquipment = $this->categoryAccount($company, [
            'code' => '1500',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::FixedAsset->value,
        ]);

        $expense = $this->draftExpense($company, ['bank_account_id' => $bank->id]);
        $this->expenseLine($expense, $officeEquipment, ['amount' => 500000, 'tax_rate_id' => null]);

        $this->recordExpense($expense)->assertRedirect();

        $entry = JournalEntry::query()->where('source_type', 'expense')->where('source_id', $expense->id)->sole();
        $assetLine = $entry->lines->firstWhere('account_id', $officeEquipment->id);
        $this->assertSame(500000, $assetLine->debit);
        $this->assertSame(0, $assetLine->credit);

        $bankLine = $entry->lines->firstWhere('account_id', $bank->ledger_account_id);
        $this->assertSame(500000, $bankLine->credit);

        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // $0-value lines: filtered from the ledger but stay on the document;
    // an all-$0 expense is rejected before anything posts.
    // ---------------------------------------------------------------

    public function test_a_zero_value_line_is_not_posted_but_stays_on_the_expense(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $freeCategory = $this->categoryAccount($company, ['code' => '5000']);
        $paidCategory = $this->categoryAccount($company, ['code' => '5010']);

        $expense = $this->draftExpense($company, ['bank_account_id' => $bank->id]);
        $freeLine = $this->expenseLine($expense, $freeCategory, ['amount' => 0, 'tax_rate_id' => null]);
        $this->expenseLine($expense, $paidCategory, ['amount' => 40000, 'tax_rate_id' => null]);

        $this->recordExpense($expense)->assertRedirect();

        $entry = JournalEntry::query()->where('source_type', 'expense')->where('source_id', $expense->id)->sole();
        // ONLY the paid category + bank — the $0 line produces NO line.
        $this->assertCount(2, $entry->lines);
        $this->assertNull($entry->lines->firstWhere('account_id', $freeCategory->id));
        $this->assertLedgerBalances();

        $expense->refresh();
        $this->assertSame(2, $expense->lines()->count());
        $freeLine->refresh();
        $this->assertSame(0, $freeLine->amount);
    }

    public function test_an_all_zero_expense_with_no_value_is_rejected_and_posts_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $category = $this->categoryAccount($company);

        $expense = $this->draftExpense($company, ['bank_account_id' => $bank->id]);
        $this->expenseLine($expense, $category, ['amount' => 0, 'tax_rate_id' => null]);

        $response = $this->recordExpense($expense);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'expense')->count());

        $expense->refresh();
        $this->assertSame(ExpenseStatus::Draft, $expense->status);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // bank_account_id: required, tenant, non-archived
    // ---------------------------------------------------------------

    public function test_recording_without_a_bank_account_returns_422_and_posts_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $category = $this->categoryAccount($company);

        $expense = $this->draftExpense($company, ['bank_account_id' => null]);
        $this->expenseLine($expense, $category, ['amount' => 10000]);

        $response = $this->recordExpense($expense);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'expense')->count());
        $this->assertSame(ExpenseStatus::Draft, $expense->fresh()->status);
    }

    public function test_recording_with_an_archived_bank_account_returns_422_and_posts_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company, ['is_archived' => true]);
        $category = $this->categoryAccount($company);

        $expense = $this->draftExpense($company, ['bank_account_id' => $bank->id]);
        $this->expenseLine($expense, $category, ['amount' => 10000]);

        $response = $this->recordExpense($expense);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'expense')->count());
    }

    // ---------------------------------------------------------------
    // expense_date defaults to today when not already set
    // ---------------------------------------------------------------

    public function test_expense_date_defaults_to_today_when_not_already_set(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $category = $this->categoryAccount($company);

        $expense = $this->draftExpense($company, ['bank_account_id' => $bank->id, 'expense_date' => null]);
        $this->expenseLine($expense, $category, ['amount' => 10000]);

        $this->recordExpense($expense)->assertRedirect();

        $this->assertSame(now()->toDateString(), $expense->fresh()->expense_date->toDateString());
    }

    // ---------------------------------------------------------------
    // Locked period
    // ---------------------------------------------------------------

    public function test_recording_into_a_locked_period_returns_422_and_posts_nothing(): void
    {
        $company = Company::factory()->create(['books_locked_through' => '2026-06-30']);
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $category = $this->categoryAccount($company);

        $expense = $this->draftExpense($company, ['bank_account_id' => $bank->id, 'expense_date' => '2026-06-15']);
        $this->expenseLine($expense, $category, ['amount' => 10000]);

        $response = $this->recordExpense($expense);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'expense')->count());

        $expense->refresh();
        $this->assertSame(ExpenseStatus::Draft, $expense->status);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Idempotency
    // ---------------------------------------------------------------

    public function test_idempotent_double_record_posts_exactly_one_entry(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $category = $this->categoryAccount($company);
        $key = (string) Str::uuid();

        $expense = $this->draftExpense($company, ['bank_account_id' => $bank->id]);
        $this->expenseLine($expense, $category, ['amount' => 10000]);

        $first = $this->recordExpense($expense, $key);
        $second = $this->recordExpense($expense, $key);

        $first->assertRedirect();
        $second->assertRedirect();

        $this->assertSame(1, JournalEntry::query()->where('source_type', 'expense')->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'expense.recorded')->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Guards: draft-only, at least one line
    // ---------------------------------------------------------------

    public function test_recording_a_non_draft_expense_returns_422_and_posts_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);

        $expense = $this->draftExpense($company, [
            'bank_account_id' => $bank->id,
            'status' => ExpenseStatus::Recorded->value,
            'recorded_at' => now(),
        ]);

        $response = $this->recordExpense($expense);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'expense')->count());
    }

    public function test_recording_a_draft_with_no_lines_returns_422_and_posts_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);

        $expense = $this->draftExpense($company, ['bank_account_id' => $bank->id]);

        $response = $this->recordExpense($expense);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'expense')->count());
    }

    // ---------------------------------------------------------------
    // Freeze: lines cannot change after record
    // ---------------------------------------------------------------

    public function test_editing_a_line_after_record_throws(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $category = $this->categoryAccount($company);

        $expense = $this->draftExpense($company, ['bank_account_id' => $bank->id]);
        $this->expenseLine($expense, $category, ['amount' => 10000]);

        $this->recordExpense($expense)->assertRedirect();

        $line = ExpenseLine::query()->sole();

        $this->expectException(\LogicException::class);
        $line->update(['description' => 'Changed after record']);
    }

    // ---------------------------------------------------------------
    // Gate: expenses.record — Owner/Admin/Accountant ok, Staff forbidden
    // ---------------------------------------------------------------

    public function test_owner_admin_and_accountant_can_record_an_expense(): void
    {
        foreach ([Role::Owner, Role::Admin, Role::Accountant] as $role) {
            $company = Company::factory()->create();
            $this->actingAsRole($company, $role);
            $bank = $this->bankAccount($company);
            $category = $this->categoryAccount($company);

            $expense = $this->draftExpense($company, ['bank_account_id' => $bank->id]);
            $this->expenseLine($expense, $category, ['amount' => 10000]);

            $this->recordExpense($expense)->assertRedirect();
        }
    }

    public function test_staff_is_forbidden_from_recording_an_expense(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);
        $bank = $this->bankAccount($company);
        $category = $this->categoryAccount($company);

        $expense = $this->draftExpense($company, ['bank_account_id' => $bank->id]);
        $this->expenseLine($expense, $category, ['amount' => 10000]);

        $response = $this->recordExpense($expense);

        $response->assertForbidden();
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'expense')->count());
    }

    // ---------------------------------------------------------------
    // Scoped 404
    // ---------------------------------------------------------------

    public function test_a_foreign_expense_404s_on_record(): void
    {
        $companyA = Company::factory()->create();
        $bankA = $this->bankAccount($companyA);
        $categoryA = $this->categoryAccount($companyA);
        $foreignExpense = $this->draftExpense($companyA, ['bank_account_id' => $bankA->id]);
        $this->expenseLine($foreignExpense, $categoryA, ['amount' => 10000]);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $this->recordExpense($foreignExpense)->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Idempotency key required
    // ---------------------------------------------------------------

    public function test_a_missing_idempotency_key_returns_422_and_posts_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $category = $this->categoryAccount($company);

        $expense = $this->draftExpense($company, ['bank_account_id' => $bank->id]);
        $this->expenseLine($expense, $category, ['amount' => 10000]);

        $response = $this->postJson("/expenses/{$expense->id}/record");

        $response->assertSessionHasErrors('idempotency_key');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'expense')->count());
    }
}
