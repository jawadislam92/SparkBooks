<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\Role;
use App\Exceptions\IneligibleCategoryAccount;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\OtherReceipt;
use App\Models\User;
use App\Services\AccountBalances;
use App\Services\OtherReceiptService;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

class OtherReceiptsTest extends TestCase
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
     * TransfersTest.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function bankAccount(Company $company, array $overrides = []): BankAccount
    {
        return app(CompanyContext::class)->runFor($company, fn () => BankAccount::factory()->create(array_merge([
            'company_id' => $company->id,
        ], $overrides)));
    }

    /**
     * Creates a plain chart-of-accounts Account scoped to the given company,
     * for use as the other-receipt category (credit) side.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function categoryAccount(Company $company, array $overrides = []): Account
    {
        return app(CompanyContext::class)->runFor($company, fn () => Account::factory()->create(array_merge([
            'company_id' => $company->id,
        ], $overrides)));
    }

    private function equityAccount(Company $company, array $overrides = []): Account
    {
        return $this->categoryAccount($company, array_merge([
            'code' => '3000',
            'type' => AccountType::Equity->value,
            'subtype' => AccountSubtype::Equity->value,
        ], $overrides));
    }

    /** @param array<string, mixed> $data */
    private function postOtherReceipt(array $data, ?string $key = null): TestResponse
    {
        return $this->postJson('/other-receipts', array_merge([
            'receipt_date' => '2026-07-01',
            'payer' => 'Test payer',
            'memo' => 'Test receipt',
        ], $data), ['X-Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    // ---------------------------------------------------------------
    // Posting rule P14: Dr deposit bank, Cr category account
    // ---------------------------------------------------------------

    public function test_other_receipt_posts_dr_bank_cr_category_and_the_entry_balances(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company, ['name' => 'Meezan Current']);
        $category = $this->equityAccount($company, ['name' => "Owner's Equity"]);

        $response = $this->postOtherReceipt([
            'bank_account_id' => $bank->id,
            'category_account_id' => $category->id,
            'amount' => 50000,
        ]);

        $response->assertRedirect();

        $receipt = OtherReceipt::query()->sole();
        $this->assertSame($bank->id, $receipt->bank_account_id);
        $this->assertSame($category->id, $receipt->category_account_id);
        $this->assertSame(50000, $receipt->amount);
        $this->assertSame('2026-07-01', $receipt->receipt_date->toDateString());
        $this->assertSame('Test payer', $receipt->payer);

        $entry = JournalEntry::query()
            ->where('source_type', 'other_receipt')
            ->where('source_id', $receipt->id)
            ->sole();
        $this->assertCount(2, $entry->lines);

        $bankLine = $entry->lines->firstWhere('account_id', $bank->ledger_account_id);
        $categoryLine = $entry->lines->firstWhere('account_id', $category->id);

        $this->assertNotNull($bankLine);
        $this->assertNotNull($categoryLine);
        $this->assertSame(50000, $bankLine->debit);
        $this->assertSame(0, $bankLine->credit);
        $this->assertSame(0, $categoryLine->debit);
        $this->assertSame(50000, $categoryLine->credit);

        $this->assertSame(
            $entry->lines->sum('debit'),
            $entry->lines->sum('credit'),
        );

        $audit = ActivityLog::query()->where('action', 'other_receipt.recorded')->sole();
        $this->assertSame($receipt->id, $audit->subject_id);
        $this->assertSame($entry->id, $audit->after['entry_id']);

        $this->assertLedgerBalances();
    }

    public function test_bank_balance_increases_and_category_account_balance_moves_correctly(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $category = $this->categoryAccount($company, [
            'code' => '4900',
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OtherIncome->value,
        ]);

        app(CompanyContext::class)->set($company);
        $this->assertSame(0, $bank->fresh()->balance());

        $this->postOtherReceipt([
            'bank_account_id' => $bank->id,
            'category_account_id' => $category->id,
            'amount' => 30000,
        ])->assertRedirect();

        $this->assertSame(30000, $bank->fresh()->balance());

        // Income is credit-normal — a credit of 30000 must present as a
        // positive 30000 balance (same signed-presentation convention as
        // AccountBalances::asOf()).
        $balances = app(AccountBalances::class)->asOf();
        $this->assertSame(30000, $balances[$category->id] ?? 0);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Category eligibility: control accounts (AR/AP) rejected
    // ---------------------------------------------------------------

    public function test_control_account_as_category_is_rejected_and_nothing_is_posted(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);

        foreach ([
            ['code' => '1100', 'subtype' => AccountSubtype::Receivable, 'type' => AccountType::Asset],
            ['code' => '2000', 'subtype' => AccountSubtype::Payable, 'type' => AccountType::Liability],
        ] as $spec) {
            $control = $this->categoryAccount($company, [
                'code' => $spec['code'],
                'type' => $spec['type']->value,
                'subtype' => $spec['subtype']->value,
            ]);

            $response = $this->postOtherReceipt([
                'bank_account_id' => $bank->id,
                'category_account_id' => $control->id,
                'amount' => 10000,
            ]);

            $response->assertSessionHasErrors('category_account_id');
            $this->assertStringContainsString('owed', session('errors')->first('category_account_id'));
        }

        $this->assertSame(0, OtherReceipt::query()->count());
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'other_receipt')->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Category eligibility: bank/cash accounts rejected (that's a transfer)
    // ---------------------------------------------------------------

    public function test_bank_or_cash_account_as_category_is_rejected_and_nothing_is_posted(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);

        foreach ([
            ['code' => '1010', 'subtype' => AccountSubtype::Cash],
            ['code' => '1020', 'subtype' => AccountSubtype::Bank],
        ] as $spec) {
            $moneyAccount = $this->categoryAccount($company, [
                'code' => $spec['code'],
                'type' => AccountType::Asset->value,
                'subtype' => $spec['subtype']->value,
            ]);

            $response = $this->postOtherReceipt([
                'bank_account_id' => $bank->id,
                'category_account_id' => $moneyAccount->id,
                'amount' => 10000,
            ]);

            $response->assertSessionHasErrors('category_account_id');
            $this->assertStringContainsString('transfer', session('errors')->first('category_account_id'));
        }

        $this->assertSame(0, OtherReceipt::query()->count());
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'other_receipt')->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Category eligibility: archived rejected
    // ---------------------------------------------------------------

    public function test_archived_category_account_is_rejected_and_nothing_is_posted(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $category = $this->equityAccount($company, ['is_archived' => true]);

        $response = $this->postOtherReceipt([
            'bank_account_id' => $bank->id,
            'category_account_id' => $category->id,
            'amount' => 10000,
        ]);

        $response->assertSessionHasErrors('category_account_id');
        $this->assertStringContainsString('archived', session('errors')->first('category_account_id'));
        $this->assertSame(0, OtherReceipt::query()->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Validation: amount > 0
    // ---------------------------------------------------------------

    public function test_zero_amount_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $category = $this->equityAccount($company);

        $response = $this->postOtherReceipt([
            'bank_account_id' => $bank->id,
            'category_account_id' => $category->id,
            'amount' => 0,
        ]);

        $response->assertSessionHasErrors('amount');
        $this->assertSame(0, OtherReceipt::query()->count());
    }

    public function test_negative_amount_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $category = $this->equityAccount($company);

        $response = $this->postOtherReceipt([
            'bank_account_id' => $bank->id,
            'category_account_id' => $category->id,
            'amount' => -500,
        ]);

        $response->assertSessionHasErrors('amount');
        $this->assertSame(0, OtherReceipt::query()->count());
    }

    // ---------------------------------------------------------------
    // Validation: deposit account must be tenant + non-archived
    // ---------------------------------------------------------------

    public function test_foreign_bank_account_is_rejected_with_a_friendly_error(): void
    {
        $companyA = Company::factory()->create();
        $foreignBank = $this->bankAccount($companyA);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        $category = $this->equityAccount($companyB);

        $response = $this->postOtherReceipt([
            'bank_account_id' => $foreignBank->id,
            'category_account_id' => $category->id,
            'amount' => 10000,
        ]);

        $response->assertSessionHasErrors('bank_account_id');
        $this->assertSame(0, OtherReceipt::query()->count());
        $this->assertLedgerBalances();
    }

    public function test_archived_bank_account_is_rejected_with_a_friendly_error(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company, ['is_archived' => true]);
        $category = $this->equityAccount($company);

        $response = $this->postOtherReceipt([
            'bank_account_id' => $bank->id,
            'category_account_id' => $category->id,
            'amount' => 10000,
        ]);

        $response->assertSessionHasErrors('bank_account_id');
        $this->assertStringContainsString('archived', session('errors')->first('bank_account_id'));
        $this->assertSame(0, OtherReceipt::query()->count());
        $this->assertLedgerBalances();
    }

    public function test_foreign_category_account_is_rejected_with_a_friendly_error(): void
    {
        $companyA = Company::factory()->create();
        $foreignCategory = $this->equityAccount($companyA);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        $bank = $this->bankAccount($companyB);

        $response = $this->postOtherReceipt([
            'bank_account_id' => $bank->id,
            'category_account_id' => $foreignCategory->id,
            'amount' => 10000,
        ]);

        $response->assertSessionHasErrors('category_account_id');
        $this->assertSame(0, OtherReceipt::query()->count());
        $this->assertLedgerBalances();
    }

    /**
     * Defense-in-depth: OtherReceiptService itself resolves the bank account
     * via a tenant-scoped findOrFail (per BelongsToCompany's global scope),
     * exactly like TransferService — proving the service is safe even if a
     * caller ever bypasses StoreOtherReceiptRequest's own friendly existence
     * check.
     */
    public function test_other_receipt_service_scopes_bank_account_lookups_and_404s_a_foreign_id(): void
    {
        $companyA = Company::factory()->create();
        $foreignBank = $this->bankAccount($companyA);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        $category = $this->equityAccount($companyB);

        $this->expectException(ModelNotFoundException::class);

        app(CompanyContext::class)->runFor($companyB, function () use ($foreignBank, $category) {
            app(OtherReceiptService::class)->record((string) Str::uuid(), [
                'bank_account_id' => $foreignBank->id,
                'category_account_id' => $category->id,
                'receipt_date' => '2026-07-01',
                'amount' => 10000,
                'memo' => 'Cross-tenant attempt',
            ]);
        });

        $this->assertSame(0, OtherReceipt::query()->count());
    }

    /**
     * The category-eligibility rule is enforced by the SERVICE itself, not
     * only by StoreOtherReceiptRequest — so a direct caller that bypasses the
     * HTTP request (e.g. the Task 9 CSV import) still can't post a disguised
     * transfer by handing a bank/cash account as the category. This is the
     * key backstop: bank/cash-as-category has no LedgerService guard (the gate
     * legitimately accepts bank/cash lines for real transfers), so if the
     * service didn't refuse it here, money-in would silently post against a
     * second bank/cash account.
     */
    public function test_service_refuses_a_bank_or_cash_category_account_and_posts_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);

        // actingAsRole doesn't run an HTTP request, so nothing sets
        // CompanyContext for the direct service call (and the count assertions
        // afterward) — set it explicitly, like PermissionMatrixTest does.
        app(CompanyContext::class)->set($company);

        foreach ([
            ['code' => '1010', 'subtype' => AccountSubtype::Cash],
            ['code' => '1020', 'subtype' => AccountSubtype::Bank],
        ] as $spec) {
            $moneyAccount = $this->categoryAccount($company, [
                'code' => $spec['code'],
                'type' => AccountType::Asset->value,
                'subtype' => $spec['subtype']->value,
            ]);

            $threw = false;
            try {
                app(OtherReceiptService::class)->record((string) Str::uuid(), [
                    'bank_account_id' => $bank->id,
                    'category_account_id' => $moneyAccount->id,
                    'receipt_date' => '2026-07-01',
                    'amount' => 10000,
                    'memo' => 'Disguised transfer attempt',
                ]);
            } catch (IneligibleCategoryAccount $e) {
                $threw = true;
                $this->assertStringContainsString('income or equity', $e->getMessage());
            }

            $this->assertTrue($threw, "record() must throw IneligibleCategoryAccount for a {$spec['subtype']->value} category account.");
        }

        // The guard runs before any write inside the transaction, so nothing
        // is persisted — no receipt, no journal entry, and the global ledger
        // invariant is untouched.
        $this->assertSame(0, OtherReceipt::query()->count());
        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertLedgerBalances();
    }

    /**
     * A control account (AR/AP) handed straight to the service is refused too
     * — money-in must never fake a receivable/payable. (On the HTTP path this
     * is also caught by StoreOtherReceiptRequest and, as a further backstop,
     * by LedgerService's ControlAccountViolation; this proves the service's
     * own guard stands on its own.)
     */
    public function test_service_refuses_a_control_category_account_and_posts_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $receivable = $this->categoryAccount($company, [
            'code' => '1100',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Receivable->value,
        ]);

        app(CompanyContext::class)->set($company);

        $threw = false;
        try {
            app(OtherReceiptService::class)->record((string) Str::uuid(), [
                'bank_account_id' => $bank->id,
                'category_account_id' => $receivable->id,
                'receipt_date' => '2026-07-01',
                'amount' => 10000,
                'memo' => 'Receivable-faking attempt',
            ]);
        } catch (IneligibleCategoryAccount $e) {
            $threw = true;
            $this->assertStringContainsString('owed', $e->getMessage());
        }

        $this->assertTrue($threw, 'record() must throw IneligibleCategoryAccount for a control (AR/AP) category account.');
        $this->assertSame(0, OtherReceipt::query()->count());
        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Idempotency
    // ---------------------------------------------------------------

    public function test_double_submit_same_idempotency_key_creates_exactly_one_receipt(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $category = $this->equityAccount($company);
        $key = (string) Str::uuid();

        $data = [
            'bank_account_id' => $bank->id,
            'category_account_id' => $category->id,
            'amount' => 25000,
        ];

        $first = $this->postOtherReceipt($data, $key);
        $second = $this->postOtherReceipt($data, $key);

        $first->assertRedirect();
        $second->assertRedirect();

        $this->assertSame(1, OtherReceipt::query()->count());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'other_receipt')->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'other_receipt.recorded')->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Locked periods
    // ---------------------------------------------------------------

    public function test_posting_into_a_locked_period_returns_error_and_persists_nothing(): void
    {
        $company = Company::factory()->create(['books_locked_through' => '2026-06-30']);
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $category = $this->equityAccount($company);

        $response = $this->postOtherReceipt([
            'bank_account_id' => $bank->id,
            'category_account_id' => $category->id,
            'amount' => 10000,
            'receipt_date' => '2026-06-15',
        ]);

        $response->assertSessionHasErrors('receipt_date');
        $this->assertStringContainsString('locked', session('errors')->first('receipt_date'));
        $this->assertSame(0, OtherReceipt::query()->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Gate: other_receipts.create
    // ---------------------------------------------------------------

    public function test_owner_admin_and_accountant_can_create_other_receipts(): void
    {
        foreach ([Role::Owner, Role::Admin, Role::Accountant] as $role) {
            $company = Company::factory()->create();
            $this->actingAsRole($company, $role);
            $bank = $this->bankAccount($company);
            $category = $this->equityAccount($company);

            $this->postOtherReceipt([
                'bank_account_id' => $bank->id,
                'category_account_id' => $category->id,
                'amount' => 10000,
            ])->assertRedirect();
        }
    }

    public function test_staff_is_forbidden_from_creating_other_receipts(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);
        $bank = $this->bankAccount($company);
        $category = $this->equityAccount($company);

        $this->get('/other-receipts/new')->assertForbidden();
        $this->postOtherReceipt([
            'bank_account_id' => $bank->id,
            'category_account_id' => $category->id,
            'amount' => 10000,
        ])->assertForbidden();

        $this->assertSame(0, OtherReceipt::query()->count());
    }

    // ---------------------------------------------------------------
    // Create form options
    // ---------------------------------------------------------------

    public function test_create_lists_only_non_archived_bank_accounts_as_deposit_options(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $active = $this->bankAccount($company, ['name' => 'Active']);
        $archived = $this->bankAccount($company, ['name' => 'Archived', 'is_archived' => true]);

        $response = $this->get('/other-receipts/new');

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) use ($active, $archived) {
            $page->component('Banking/OtherReceipt');
            $page->where('bankAccounts', function ($accounts) use ($active, $archived) {
                $ids = collect($accounts)->pluck('id')->all();

                return in_array($active->id, $ids, true) && ! in_array($archived->id, $ids, true);
            });
        });
    }

    public function test_create_lists_only_eligible_accounts_as_category_options(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $equity = $this->equityAccount($company, ['name' => 'Eligible equity']);
        $receivable = $this->categoryAccount($company, [
            'code' => '1100', 'type' => AccountType::Asset->value, 'subtype' => AccountSubtype::Receivable->value,
        ]);
        $payable = $this->categoryAccount($company, [
            'code' => '2000', 'type' => AccountType::Liability->value, 'subtype' => AccountSubtype::Payable->value,
        ]);
        $cash = $this->categoryAccount($company, [
            'code' => '1010', 'type' => AccountType::Asset->value, 'subtype' => AccountSubtype::Cash->value,
        ]);
        $bankSubtype = $this->categoryAccount($company, [
            'code' => '1020', 'type' => AccountType::Asset->value, 'subtype' => AccountSubtype::Bank->value,
        ]);
        $archived = $this->equityAccount($company, ['code' => '3100', 'name' => 'Archived equity', 'is_archived' => true]);

        $response = $this->get('/other-receipts/new');

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) use ($equity, $receivable, $payable, $cash, $bankSubtype, $archived) {
            $page->component('Banking/OtherReceipt');
            $page->where('categoryAccounts', function ($accounts) use ($equity, $receivable, $payable, $cash, $bankSubtype, $archived) {
                $ids = collect($accounts)->pluck('id')->all();

                return in_array($equity->id, $ids, true)
                    && ! in_array($receivable->id, $ids, true)
                    && ! in_array($payable->id, $ids, true)
                    && ! in_array($cash->id, $ids, true)
                    && ! in_array($bankSubtype->id, $ids, true)
                    && ! in_array($archived->id, $ids, true);
            });
        });
    }

    // ---------------------------------------------------------------
    // Scoping
    // ---------------------------------------------------------------

    public function test_other_receipts_are_scoped_to_the_current_company(): void
    {
        $companyA = Company::factory()->create();
        $this->actingAsRole($companyA, Role::Owner);
        $bankA = $this->bankAccount($companyA);
        $categoryA = $this->equityAccount($companyA);
        $this->postOtherReceipt([
            'bank_account_id' => $bankA->id,
            'category_account_id' => $categoryA->id,
            'amount' => 10000,
        ])->assertRedirect();

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        // actingAsRole doesn't perform an HTTP request, so CompanyContext (a
        // scoped singleton normally re-derived per-request by
        // SetCompanyContext) is still left pointed at company A from the
        // postOtherReceipt() call above — runFor() switches it explicitly for
        // this direct model query, the same way TransfersTest's bankAccount()
        // helper does for factory creation.
        $countForB = app(CompanyContext::class)->runFor($companyB, fn () => OtherReceipt::query()->count());
        $countForA = app(CompanyContext::class)->runFor($companyA, fn () => OtherReceipt::query()->count());

        $this->assertSame(0, $countForB, 'company B must not see company A\'s other receipt.');
        $this->assertSame(1, $countForA);
    }
}
