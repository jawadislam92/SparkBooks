<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ContactType;
use App\Enums\EntryRole;
use App\Enums\Role;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\OpeningBalanceRun;
use App\Models\OpeningDocument;
use App\Models\User;
use App\Services\AccountBalances;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

class OpeningBalancesTest extends TestCase
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

    /**
     * Creates a BankAccount (with its nested, factory-generated linked ledger
     * Account) scoped to the given company. Wrapped in CompanyContext::runFor
     * because BankAccountFactory's ledger_account_id is itself a nested
     * Account::factory() — that Account's own BelongsToCompany creating hook
     * needs a live CompanyContext to fill its company_id. Mirrors
     * TransfersTest/OtherReceiptsTest.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function bankAccount(Company $company, array $overrides = []): BankAccount
    {
        return app(CompanyContext::class)->runFor($company, fn () => BankAccount::factory()->create(array_merge([
            'company_id' => $company->id,
        ], $overrides)));
    }

    /** @param array<string, mixed> $overrides */
    private function account(Company $company, array $overrides = []): Account
    {
        return app(CompanyContext::class)->runFor($company, fn () => Account::factory()->create(array_merge([
            'company_id' => $company->id,
        ], $overrides)));
    }

    private function arAccount(Company $company): Account
    {
        return $this->account($company, [
            'code' => '1100', 'name' => 'Accounts Receivable',
            'type' => AccountType::Asset->value, 'subtype' => AccountSubtype::Receivable->value, 'is_system' => true,
        ]);
    }

    private function apAccount(Company $company): Account
    {
        return $this->account($company, [
            'code' => '2000', 'name' => 'Accounts Payable',
            'type' => AccountType::Liability->value, 'subtype' => AccountSubtype::Payable->value, 'is_system' => true,
        ]);
    }

    private function obeAccount(Company $company): Account
    {
        return $this->account($company, [
            'code' => '3100', 'name' => 'Opening Balance Equity',
            'type' => AccountType::Equity->value, 'subtype' => AccountSubtype::Equity->value, 'is_system' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function contact(Company $company, array $overrides = []): Contact
    {
        return app(CompanyContext::class)->runFor($company, fn () => Contact::factory()->create(array_merge([
            'company_id' => $company->id,
        ], $overrides)));
    }

    private function customer(Company $company, array $overrides = []): Contact
    {
        return $this->contact($company, array_merge(['type' => ContactType::Customer->value], $overrides));
    }

    private function vendor(Company $company, array $overrides = []): Contact
    {
        return $this->contact($company, array_merge(['type' => ContactType::Vendor->value], $overrides));
    }

    /** Sets up the standard chart-of-accounts trio this feature depends on (1100/2000/3100). */
    private function chart(Company $company): array
    {
        return [
            'ar' => $this->arAccount($company),
            'ap' => $this->apAccount($company),
            'obe' => $this->obeAccount($company),
        ];
    }

    /** @param array<string, mixed> $data */
    private function postOpeningBalances(array $data, ?string $key = null): TestResponse
    {
        return $this->postJson('/setup/opening-balances', array_merge([
            'books_start_date' => '2026-07-01',
            'bank_lines' => [],
            'customer_lines' => [],
            'vendor_lines' => [],
        ], $data), ['X-Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    // ---------------------------------------------------------------
    // P8a/b/c exact lines + the OBE plug
    // ---------------------------------------------------------------

    public function test_apply_posts_p8a_dr_bank_p8b_dr_ar_with_contact_p8c_cr_ap_with_contact_and_the_entry_balances(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        ['ar' => $ar, 'ap' => $ap, 'obe' => $obe] = $this->chart($company);
        $bank = $this->bankAccount($company);
        $customerA = $this->customer($company, ['name' => 'Acme Traders']);
        $vendorA = $this->vendor($company, ['name' => 'Beta Supplies']);

        $response = $this->postOpeningBalances([
            'books_start_date' => '2026-07-01',
            'bank_lines' => [
                ['bank_account_id' => $bank->id, 'amount' => 500000],
            ],
            'customer_lines' => [
                ['contact_id' => $customerA->id, 'reference' => 'INV-OLD-1', 'original_date' => '2026-05-01', 'due_date' => '2026-06-01', 'amount' => 200000],
            ],
            'vendor_lines' => [
                ['contact_id' => $vendorA->id, 'reference' => 'BILL-OLD-1', 'original_date' => '2026-05-15', 'due_date' => '2026-06-15', 'amount' => 80000],
            ],
        ]);

        $response->assertRedirect();

        $run = OpeningBalanceRun::query()->sole();
        $this->assertSame('active', $run->status);

        $entry = JournalEntry::query()
            ->where('source_type', 'opening_balance_run')
            ->where('source_id', $run->id)
            ->sole();
        $this->assertCount(4, $entry->lines);

        $bankLine = $entry->lines->firstWhere('account_id', $bank->ledger_account_id);
        $arLine = $entry->lines->firstWhere('account_id', $ar->id);
        $apLine = $entry->lines->firstWhere('account_id', $ap->id);
        $obeLine = $entry->lines->firstWhere('account_id', $obe->id);

        $this->assertNotNull($bankLine);
        $this->assertSame(500000, $bankLine->debit);
        $this->assertSame(0, $bankLine->credit);
        $this->assertNull($bankLine->contact_id);

        $this->assertNotNull($arLine);
        $this->assertSame(200000, $arLine->debit);
        $this->assertSame(0, $arLine->credit);
        $this->assertSame($customerA->id, $arLine->contact_id, 'P8b AR line must carry the customer contact_id — this is what makes aging tie out.');

        $this->assertNotNull($apLine);
        $this->assertSame(0, $apLine->debit);
        $this->assertSame(80000, $apLine->credit);
        $this->assertSame($vendorA->id, $apLine->contact_id, 'P8c AP line must carry the vendor contact_id — this is what makes aging tie out.');

        // Exactly one OBE plug line: total debits (500000+200000=700000) minus
        // total credits (80000) = 620000, credited (debits > credits is the
        // normal case: assets+receivables exceed payables).
        $this->assertSame(1, $entry->lines->where('account_id', $obe->id)->count());
        $this->assertNotNull($obeLine);
        $this->assertSame(0, $obeLine->debit);
        $this->assertSame(620000, $obeLine->credit);
        $this->assertNull($obeLine->contact_id);

        $this->assertSame($entry->lines->sum('debit'), $entry->lines->sum('credit'));
        $this->assertLedgerBalances();

        $audit = ActivityLog::query()->where('action', 'opening_balances.applied')->sole();
        $this->assertSame($run->id, $audit->subject_id);
        $this->assertSame($entry->id, $audit->after['entry_id']);
    }

    public function test_obe_plug_debits_when_vendor_bills_exceed_assets_and_receivables(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        ['ap' => $ap, 'obe' => $obe] = $this->chart($company);
        $vendorA = $this->vendor($company);

        $this->postOpeningBalances([
            'vendor_lines' => [
                ['contact_id' => $vendorA->id, 'original_date' => '2026-05-01', 'due_date' => '2026-06-01', 'amount' => 100000],
            ],
        ])->assertRedirect();

        $run = OpeningBalanceRun::query()->sole();
        $entry = JournalEntry::query()->where('source_type', 'opening_balance_run')->where('source_id', $run->id)->sole();

        $this->assertCount(2, $entry->lines);
        $obeLine = $entry->lines->firstWhere('account_id', $obe->id);
        $this->assertSame(100000, $obeLine->debit);
        $this->assertSame(0, $obeLine->credit);

        $apLine = $entry->lines->firstWhere('account_id', $ap->id);
        $this->assertSame(100000, $apLine->credit);

        $this->assertLedgerBalances();
    }

    public function test_no_obe_line_is_posted_when_lines_already_balance(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        ['ar' => $ar, 'ap' => $ap] = $this->chart($company);
        $customerA = $this->customer($company);
        $vendorA = $this->vendor($company);

        // AR 50000 debit == AP 50000 credit — already balanced without a plug.
        $this->postOpeningBalances([
            'customer_lines' => [
                ['contact_id' => $customerA->id, 'original_date' => '2026-05-01', 'due_date' => '2026-06-01', 'amount' => 50000],
            ],
            'vendor_lines' => [
                ['contact_id' => $vendorA->id, 'original_date' => '2026-05-01', 'due_date' => '2026-06-01', 'amount' => 50000],
            ],
        ])->assertRedirect();

        $run = OpeningBalanceRun::query()->sole();
        $entry = JournalEntry::query()->where('source_type', 'opening_balance_run')->where('source_id', $run->id)->sole();

        $this->assertCount(2, $entry->lines, 'No OBE plug line should exist when debits already equal credits.');
        $this->assertSame($entry->lines->sum('debit'), $entry->lines->sum('credit'));
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Opening documents (per-contact subledger rows for Phase 5 aging)
    // ---------------------------------------------------------------

    public function test_opening_documents_are_created_per_customer_and_vendor_line_with_contact_dates_and_amount(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->chart($company);
        $customerA = $this->customer($company);
        $vendorA = $this->vendor($company);

        $this->postOpeningBalances([
            'customer_lines' => [
                ['contact_id' => $customerA->id, 'reference' => 'INV-OLD-1', 'original_date' => '2026-05-01', 'due_date' => '2026-06-01', 'amount' => 150000],
            ],
            'vendor_lines' => [
                ['contact_id' => $vendorA->id, 'reference' => 'BILL-OLD-1', 'original_date' => '2026-05-10', 'due_date' => '2026-06-10', 'amount' => 60000],
            ],
        ])->assertRedirect();

        $run = OpeningBalanceRun::query()->sole();

        $customerDoc = OpeningDocument::query()->where('kind', 'receivable')->sole();
        $this->assertSame($run->id, $customerDoc->opening_balance_run_id);
        $this->assertSame($customerA->id, $customerDoc->contact_id);
        $this->assertSame('INV-OLD-1', $customerDoc->reference);
        $this->assertSame('2026-05-01', $customerDoc->original_date->toDateString());
        $this->assertSame('2026-06-01', $customerDoc->due_date->toDateString());
        $this->assertSame(150000, $customerDoc->amount);
        $this->assertSame('open', $customerDoc->status);

        $vendorDoc = OpeningDocument::query()->where('kind', 'payable')->sole();
        $this->assertSame($run->id, $vendorDoc->opening_balance_run_id);
        $this->assertSame($vendorA->id, $vendorDoc->contact_id);
        $this->assertSame('BILL-OLD-1', $vendorDoc->reference);
        $this->assertSame('2026-05-10', $vendorDoc->original_date->toDateString());
        $this->assertSame('2026-06-10', $vendorDoc->due_date->toDateString());
        $this->assertSame(60000, $vendorDoc->amount);
        $this->assertSame('open', $vendorDoc->status);
    }

    // ---------------------------------------------------------------
    // Dates: books_start_date stored on the company; entry dated the day before
    // ---------------------------------------------------------------

    public function test_books_start_date_is_stored_on_the_company_and_the_entry_is_dated_the_day_before(): void
    {
        $company = Company::factory()->create(['books_start_date' => null]);
        $this->actingAsRole($company, Role::Owner);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $this->postOpeningBalances([
            'books_start_date' => '2026-07-01',
            'bank_lines' => [['bank_account_id' => $bank->id, 'amount' => 10000]],
        ])->assertRedirect();

        $this->assertSame('2026-07-01', $company->fresh()->books_start_date->toDateString());

        $run = OpeningBalanceRun::query()->sole();
        $this->assertSame('2026-07-01', $run->books_start_date->toDateString());

        $entry = JournalEntry::query()->where('source_type', 'opening_balance_run')->where('source_id', $run->id)->sole();
        $this->assertSame('2026-06-30', $entry->entry_date->toDateString());
    }

    // ---------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------

    public function test_customer_line_without_contact_id_is_rejected_and_nothing_is_posted(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->chart($company);

        $response = $this->postOpeningBalances([
            'customer_lines' => [
                ['original_date' => '2026-05-01', 'due_date' => '2026-06-01', 'amount' => 10000],
            ],
        ]);

        $response->assertSessionHasErrors('customer_lines.0.contact_id');
        $this->assertSame(0, OpeningBalanceRun::query()->count());
        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertLedgerBalances();
    }

    public function test_at_least_one_line_is_required(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->chart($company);

        $response = $this->postOpeningBalances([]);

        $response->assertSessionHasErrors('bank_lines');
        $this->assertSame(0, OpeningBalanceRun::query()->count());
    }

    public function test_zero_amount_bank_line_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $response = $this->postOpeningBalances([
            'bank_lines' => [['bank_account_id' => $bank->id, 'amount' => 0]],
        ]);

        $response->assertSessionHasErrors('bank_lines.0.amount');
        $this->assertSame(0, OpeningBalanceRun::query()->count());
    }

    public function test_due_date_before_original_date_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->chart($company);
        $customerA = $this->customer($company);

        $response = $this->postOpeningBalances([
            'customer_lines' => [
                ['contact_id' => $customerA->id, 'original_date' => '2026-06-01', 'due_date' => '2026-05-01', 'amount' => 10000],
            ],
        ]);

        $response->assertSessionHasErrors('customer_lines.0.due_date');
        $this->assertSame(0, OpeningBalanceRun::query()->count());
    }

    public function test_foreign_bank_account_is_rejected_with_a_friendly_error(): void
    {
        $companyA = Company::factory()->create();
        $foreignBank = $this->bankAccount($companyA);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        $this->chart($companyB);

        $response = $this->postOpeningBalances([
            'bank_lines' => [['bank_account_id' => $foreignBank->id, 'amount' => 10000]],
        ]);

        $response->assertSessionHasErrors('bank_lines.0.bank_account_id');
        $this->assertSame(0, OpeningBalanceRun::query()->count());
        $this->assertLedgerBalances();
    }

    public function test_foreign_contact_is_rejected_with_a_friendly_error(): void
    {
        $companyA = Company::factory()->create();
        $foreignCustomer = $this->customer($companyA);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        $this->chart($companyB);

        $response = $this->postOpeningBalances([
            'customer_lines' => [
                ['contact_id' => $foreignCustomer->id, 'original_date' => '2026-05-01', 'due_date' => '2026-06-01', 'amount' => 10000],
            ],
        ]);

        $response->assertSessionHasErrors('customer_lines.0.contact_id');
        $this->assertSame(0, OpeningBalanceRun::query()->count());
        $this->assertLedgerBalances();
    }

    public function test_archived_bank_account_is_rejected_with_a_friendly_error(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->chart($company);
        $bank = $this->bankAccount($company, ['is_archived' => true]);

        $response = $this->postOpeningBalances([
            'bank_lines' => [['bank_account_id' => $bank->id, 'amount' => 10000]],
        ]);

        $response->assertSessionHasErrors('bank_lines.0.bank_account_id');
        $this->assertStringContainsString('archived', session('errors')->first('bank_lines.0.bank_account_id'));
        $this->assertSame(0, OpeningBalanceRun::query()->count());
    }

    // ---------------------------------------------------------------
    // Locked periods
    // ---------------------------------------------------------------

    public function test_posting_into_a_locked_period_returns_error_and_persists_nothing(): void
    {
        $company = Company::factory()->create(['books_locked_through' => '2026-06-30']);
        $this->actingAsRole($company, Role::Owner);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        // entry_date will be 2026-06-30 (day before books_start_date), which
        // is <= books_locked_through — LedgerService must reject it.
        $response = $this->postOpeningBalances([
            'books_start_date' => '2026-07-01',
            'bank_lines' => [['bank_account_id' => $bank->id, 'amount' => 10000]],
        ]);

        $response->assertSessionHasErrors('books_start_date');
        $this->assertStringContainsString('locked', session('errors')->first('books_start_date'));
        $this->assertSame(0, OpeningBalanceRun::query()->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Idempotency
    // ---------------------------------------------------------------

    public function test_double_submit_same_idempotency_key_creates_exactly_one_run(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->chart($company);
        $bank = $this->bankAccount($company);
        $key = (string) Str::uuid();

        $data = ['bank_lines' => [['bank_account_id' => $bank->id, 'amount' => 25000]]];

        $this->postOpeningBalances($data, $key)->assertRedirect();
        $this->postOpeningBalances($data, $key)->assertRedirect();

        $this->assertSame(1, OpeningBalanceRun::query()->count());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'opening_balance_run')->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'opening_balances.applied')->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Re-run: voids the prior run and posts fresh balances
    // ---------------------------------------------------------------

    public function test_rerun_voids_the_prior_run_and_posts_only_the_new_balances(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->chart($company);
        $bank = $this->bankAccount($company);
        $customerA = $this->customer($company);
        $vendorB = $this->vendor($company);

        // First run: bank 500000 + customer 200000.
        $this->postOpeningBalances([
            'books_start_date' => '2026-07-01',
            'bank_lines' => [['bank_account_id' => $bank->id, 'amount' => 500000]],
            'customer_lines' => [
                ['contact_id' => $customerA->id, 'original_date' => '2026-05-01', 'due_date' => '2026-06-01', 'amount' => 200000],
            ],
        ])->assertRedirect();

        $run1 = OpeningBalanceRun::query()->sole();
        $entry1 = JournalEntry::query()->where('source_type', 'opening_balance_run')->where('source_id', $run1->id)->where('entry_role', EntryRole::Primary->value)->sole();
        $customerDoc1 = OpeningDocument::query()->where('opening_balance_run_id', $run1->id)->sole();

        // Re-run with different data: bank 300000, vendor 50000, no customer.
        $response = $this->postOpeningBalances([
            'books_start_date' => '2026-08-01',
            'bank_lines' => [['bank_account_id' => $bank->id, 'amount' => 300000]],
            'vendor_lines' => [
                ['contact_id' => $vendorB->id, 'original_date' => '2026-07-01', 'due_date' => '2026-08-01', 'amount' => 50000],
            ],
        ]);

        $response->assertRedirect();

        // Exactly two runs: the voided original + the new active one.
        $this->assertSame(2, OpeningBalanceRun::query()->count());
        $run1->refresh();
        $this->assertSame('voided', $run1->status);
        $run2 = OpeningBalanceRun::query()->where('status', 'active')->sole();
        $this->assertNotSame($run1->id, $run2->id);
        $this->assertSame('2026-08-01', $run2->books_start_date->toDateString());

        // Prior run's opening document is voided; a fresh document exists for the new run.
        $this->assertSame('void', $customerDoc1->fresh()->status);
        $vendorDoc2 = OpeningDocument::query()->where('opening_balance_run_id', $run2->id)->sole();
        $this->assertSame('open', $vendorDoc2->status);
        $this->assertSame($vendorB->id, $vendorDoc2->contact_id);

        // Exactly 3 journal entries total for this source type: run1 primary,
        // run1 reversal, run2 primary. No duplicate on the same source (the
        // §17.2 unique index would reject that outright).
        $this->assertSame(3, JournalEntry::query()->where('source_type', 'opening_balance_run')->count());

        $reversal = JournalEntry::query()
            ->where('source_type', 'opening_balance_run')
            ->where('source_id', $run1->id)
            ->where('entry_role', EntryRole::Reversal->value)
            ->sole();
        $this->assertSame($entry1->id, $reversal->reverses_entry_id);
        // Mirror-image of entry1's lines.
        foreach ($entry1->lines as $originalLine) {
            $mirror = $reversal->lines->firstWhere('account_id', $originalLine->account_id);
            $this->assertNotNull($mirror);
            $this->assertSame($originalLine->credit, $mirror->debit);
            $this->assertSame($originalLine->debit, $mirror->credit);
            $this->assertSame($originalLine->contact_id, $mirror->contact_id);
        }

        $entry2 = JournalEntry::query()
            ->where('source_type', 'opening_balance_run')
            ->where('source_id', $run2->id)
            ->where('entry_role', EntryRole::Primary->value)
            ->sole();
        $this->assertSame('2026-07-31', $entry2->entry_date->toDateString());

        // Net ledger effect: run1's bank/AR balances are fully reversed out,
        // leaving only run2's balances live.
        app(CompanyContext::class)->set($company);
        $balances = app(AccountBalances::class)->asOf();
        $this->assertSame(300000, $balances[$bank->ledger_account_id] ?? 0, 'Bank balance must reflect only the new run (500000 posted then fully reversed, plus a fresh 300000).');

        $ar = Account::query()->where('code', '1100')->sole();
        $this->assertSame(0, $balances[$ar->id] ?? 0, 'AR must net to zero: run1 posted 200000 then it was fully reversed, and run2 has no customer lines.');

        $ap = Account::query()->where('code', '2000')->sole();
        $this->assertSame(50000, $balances[$ap->id] ?? 0, 'AP must reflect only run2\'s new vendor line.');

        $this->assertSame(2, ActivityLog::query()->where('action', 'opening_balances.applied')->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Re-run guard: locked once a real transaction exists
    // ---------------------------------------------------------------

    public function test_rerun_is_refused_once_a_real_transaction_exists_and_nothing_changes(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $this->postOpeningBalances([
            'bank_lines' => [['bank_account_id' => $bank->id, 'amount' => 500000]],
        ])->assertRedirect();

        $run1 = OpeningBalanceRun::query()->sole();

        // A real (non-opening) transaction: a manual journal entry.
        $expense = $this->account($company, [
            'code' => '5000', 'type' => AccountType::Expense->value, 'subtype' => AccountSubtype::OperatingExpense->value,
        ]);
        $this->postJson('/journal-entries', [
            'entry_date' => '2026-07-05',
            'memo' => 'Rent',
            'lines' => [
                ['account_id' => $expense->id, 'debit' => 10000, 'credit' => 0],
                ['account_id' => $bank->ledger_account_id, 'debit' => 0, 'credit' => 10000],
            ],
        ], ['X-Idempotency-Key' => (string) Str::uuid()])->assertRedirect();

        $response = $this->postOpeningBalances([
            'books_start_date' => '2026-08-01',
            'bank_lines' => [['bank_account_id' => $bank->id, 'amount' => 999999]],
        ]);

        $response->assertSessionHasErrors('books_start_date');
        $this->assertStringContainsString('locked', session('errors')->first('books_start_date'));

        // Nothing changed: still one run, still active, no reversal, no new documents.
        $this->assertSame(1, OpeningBalanceRun::query()->count());
        $this->assertSame('active', $run1->fresh()->status);
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'opening_balance_run')->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Gate: settings.manage (Owner + Admin only — not Accountant, per §7.14)
    // ---------------------------------------------------------------

    public function test_owner_and_admin_can_apply_opening_balances(): void
    {
        foreach ([Role::Owner, Role::Admin] as $role) {
            $company = Company::factory()->create();
            $this->actingAsRole($company, $role);
            $this->chart($company);
            $bank = $this->bankAccount($company);

            $this->postOpeningBalances([
                'bank_lines' => [['bank_account_id' => $bank->id, 'amount' => 10000]],
            ])->assertRedirect();
        }
    }

    public function test_accountant_is_forbidden_from_applying_opening_balances(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Accountant);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $this->get('/setup/opening-balances')->assertForbidden();
        $this->postOpeningBalances([
            'bank_lines' => [['bank_account_id' => $bank->id, 'amount' => 10000]],
        ])->assertForbidden();

        $this->assertSame(0, OpeningBalanceRun::query()->count());
    }

    public function test_staff_is_forbidden_from_applying_opening_balances(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $this->get('/setup/opening-balances')->assertForbidden();
        $this->postOpeningBalances([
            'bank_lines' => [['bank_account_id' => $bank->id, 'amount' => 10000]],
        ])->assertForbidden();

        $this->assertSame(0, OpeningBalanceRun::query()->count());
    }

    // ---------------------------------------------------------------
    // Scoping
    // ---------------------------------------------------------------

    public function test_opening_balance_runs_are_scoped_to_the_current_company(): void
    {
        $companyA = Company::factory()->create();
        $this->actingAsRole($companyA, Role::Owner);
        $this->chart($companyA);
        $bankA = $this->bankAccount($companyA);
        $this->postOpeningBalances([
            'bank_lines' => [['bank_account_id' => $bankA->id, 'amount' => 10000]],
        ])->assertRedirect();

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $countForB = app(CompanyContext::class)->runFor($companyB, fn () => OpeningBalanceRun::query()->count());
        $countForA = app(CompanyContext::class)->runFor($companyA, fn () => OpeningBalanceRun::query()->count());

        $this->assertSame(0, $countForB, 'company B must not see company A\'s opening balance run.');
        $this->assertSame(1, $countForA);
    }

    // ---------------------------------------------------------------
    // Create form options
    // ---------------------------------------------------------------

    public function test_create_lists_only_non_archived_bank_accounts_and_splits_contacts_by_type(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->chart($company);
        $active = $this->bankAccount($company, ['name' => 'Active']);
        $archived = $this->bankAccount($company, ['name' => 'Archived', 'is_archived' => true]);
        $customerA = $this->customer($company);
        $vendorA = $this->vendor($company);
        $both = $this->contact($company, ['type' => ContactType::Both->value]);
        $archivedContact = $this->customer($company, ['is_archived' => true]);

        $response = $this->get('/setup/opening-balances');

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) use ($active, $archived, $customerA, $vendorA, $both, $archivedContact) {
            $page->component('Setup/OpeningBalances');
            $page->where('bankAccounts', function ($accounts) use ($active, $archived) {
                $ids = collect($accounts)->pluck('id')->all();

                return in_array($active->id, $ids, true) && ! in_array($archived->id, $ids, true);
            });
            $page->where('customers', function ($contacts) use ($customerA, $both, $vendorA, $archivedContact) {
                $ids = collect($contacts)->pluck('id')->all();

                return in_array($customerA->id, $ids, true)
                    && in_array($both->id, $ids, true)
                    && ! in_array($vendorA->id, $ids, true)
                    && ! in_array($archivedContact->id, $ids, true);
            });
            $page->where('vendors', function ($contacts) use ($vendorA, $both, $customerA) {
                $ids = collect($contacts)->pluck('id')->all();

                return in_array($vendorA->id, $ids, true)
                    && in_array($both->id, $ids, true)
                    && ! in_array($customerA->id, $ids, true);
            });
            $page->where('activeRun', null);
        });
    }

    public function test_create_shows_the_active_run_summary_when_one_exists(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->chart($company);
        $bank = $this->bankAccount($company);
        $customerA = $this->customer($company);

        $this->postOpeningBalances([
            'books_start_date' => '2026-07-01',
            'bank_lines' => [['bank_account_id' => $bank->id, 'amount' => 500000]],
            'customer_lines' => [
                ['contact_id' => $customerA->id, 'original_date' => '2026-05-01', 'due_date' => '2026-06-01', 'amount' => 200000],
            ],
        ])->assertRedirect();

        $response = $this->get('/setup/opening-balances');

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) {
            $page->component('Setup/OpeningBalances');
            $page->where('activeRun.books_start_date', '2026-07-01');
            $page->where('activeRun.customer_count', 1);
            $page->where('activeRun.vendor_count', 0);
            $page->where('activeRun.total_receivable', 200000);
        });
    }
}
