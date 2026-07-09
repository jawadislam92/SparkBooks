<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Transfer;
use App\Models\User;
use App\Services\TransferService;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

class TransfersTest extends TestCase
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
     * needs a live CompanyContext to fill its company_id (the explicit
     * `company_id` override below only reaches the top-level BankAccount
     * attributes, not the nested factory).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function bankAccount(Company $company, array $overrides = []): BankAccount
    {
        return app(CompanyContext::class)->runFor($company, fn () => BankAccount::factory()->create(array_merge([
            'company_id' => $company->id,
        ], $overrides)));
    }

    /** @param array<string, mixed> $data */
    private function postTransfer(array $data, ?string $key = null): TestResponse
    {
        return $this->postJson('/transfers', array_merge([
            'transfer_date' => '2026-07-01',
            'memo' => 'Test transfer',
        ], $data), ['X-Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    // ---------------------------------------------------------------
    // Posting rule P11: Dr destination, Cr source
    // ---------------------------------------------------------------

    public function test_transfer_posts_dr_destination_cr_source_and_the_entry_balances(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $from = $this->bankAccount($company, ['name' => 'Meezan Current']);
        $to = $this->bankAccount($company, ['name' => 'Petty Cash']);

        $response = $this->postTransfer([
            'from_bank_account_id' => $from->id,
            'to_bank_account_id' => $to->id,
            'amount' => 50000,
        ]);

        $response->assertRedirect();

        $transfer = Transfer::query()->sole();
        $this->assertSame($from->id, $transfer->from_bank_account_id);
        $this->assertSame($to->id, $transfer->to_bank_account_id);
        $this->assertSame(50000, $transfer->amount);
        $this->assertSame('2026-07-01', $transfer->transfer_date->toDateString());

        $entry = JournalEntry::query()
            ->where('source_type', 'transfer')
            ->where('source_id', $transfer->id)
            ->sole();
        $this->assertCount(2, $entry->lines);

        $toLine = $entry->lines->firstWhere('account_id', $to->ledger_account_id);
        $fromLine = $entry->lines->firstWhere('account_id', $from->ledger_account_id);

        $this->assertNotNull($toLine);
        $this->assertNotNull($fromLine);
        $this->assertSame(50000, $toLine->debit);
        $this->assertSame(0, $toLine->credit);
        $this->assertSame(0, $fromLine->debit);
        $this->assertSame(50000, $fromLine->credit);

        // The entry itself balances (also proven globally by assertLedgerBalances()).
        $this->assertSame(
            $entry->lines->sum('debit'),
            $entry->lines->sum('credit'),
        );

        $audit = ActivityLog::query()->where('action', 'transfer.created')->sole();
        $this->assertSame($transfer->id, $audit->subject_id);
        $this->assertSame($entry->id, $audit->after['entry_id']);

        $this->assertLedgerBalances();
    }

    public function test_from_account_balance_decreases_and_to_account_increases_by_the_transferred_amount(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $from = $this->bankAccount($company);
        $to = $this->bankAccount($company);

        app(CompanyContext::class)->set($company);
        $income = Account::factory()->create([
            'company_id' => $company->id,
            'code' => '4000',
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OperatingIncome->value,
        ]);

        $this->postJson('/journal-entries', [
            'entry_date' => '2026-07-01',
            'memo' => 'Opening deposit',
            'lines' => [
                ['account_id' => $from->ledger_account_id, 'debit' => 100000, 'credit' => 0],
                ['account_id' => $income->id, 'debit' => 0, 'credit' => 100000],
            ],
        ], ['X-Idempotency-Key' => (string) Str::uuid()])->assertRedirect();

        $this->assertSame(100000, $from->fresh()->balance());
        $this->assertSame(0, $to->fresh()->balance());

        $this->postTransfer([
            'from_bank_account_id' => $from->id,
            'to_bank_account_id' => $to->id,
            'amount' => 30000,
        ])->assertRedirect();

        $this->assertSame(70000, $from->fresh()->balance());
        $this->assertSame(30000, $to->fresh()->balance());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Validation: from != to
    // ---------------------------------------------------------------

    public function test_transfer_to_the_same_account_is_rejected_and_nothing_is_posted(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $account = $this->bankAccount($company);

        $response = $this->postTransfer([
            'from_bank_account_id' => $account->id,
            'to_bank_account_id' => $account->id,
            'amount' => 10000,
        ]);

        $response->assertSessionHasErrors('to_bank_account_id');
        $this->assertStringContainsString('different accounts', session('errors')->first('to_bank_account_id'));
        $this->assertSame(0, Transfer::query()->count());
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'transfer')->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Validation: amount > 0
    // ---------------------------------------------------------------

    public function test_zero_amount_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $from = $this->bankAccount($company);
        $to = $this->bankAccount($company);

        $response = $this->postTransfer([
            'from_bank_account_id' => $from->id,
            'to_bank_account_id' => $to->id,
            'amount' => 0,
        ]);

        $response->assertSessionHasErrors('amount');
        $this->assertSame(0, Transfer::query()->count());
    }

    public function test_negative_amount_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $from = $this->bankAccount($company);
        $to = $this->bankAccount($company);

        $response = $this->postTransfer([
            'from_bank_account_id' => $from->id,
            'to_bank_account_id' => $to->id,
            'amount' => -500,
        ]);

        $response->assertSessionHasErrors('amount');
        $this->assertSame(0, Transfer::query()->count());
    }

    // ---------------------------------------------------------------
    // Validation: both accounts must be tenant + non-archived
    // ---------------------------------------------------------------

    public function test_foreign_bank_account_is_rejected_with_a_friendly_422(): void
    {
        $companyA = Company::factory()->create();
        $foreignAccount = $this->bankAccount($companyA);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        $localAccount = $this->bankAccount($companyB);

        $response = $this->postTransfer([
            'from_bank_account_id' => $localAccount->id,
            'to_bank_account_id' => $foreignAccount->id,
            'amount' => 10000,
        ]);

        $response->assertSessionHasErrors('to_bank_account_id');
        $this->assertSame(0, Transfer::query()->count());
        $this->assertLedgerBalances();
    }

    public function test_archived_bank_account_is_rejected_with_a_friendly_422(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $from = $this->bankAccount($company);
        $to = $this->bankAccount($company, ['is_archived' => true]);

        $response = $this->postTransfer([
            'from_bank_account_id' => $from->id,
            'to_bank_account_id' => $to->id,
            'amount' => 10000,
        ]);

        $response->assertSessionHasErrors('to_bank_account_id');
        $this->assertStringContainsString('archived', session('errors')->first('to_bank_account_id'));
        $this->assertSame(0, Transfer::query()->count());
        $this->assertLedgerBalances();
    }

    /**
     * Defense-in-depth: TransferService itself resolves bank accounts via a
     * tenant-scoped findOrFail (per BelongsToCompany's global scope), exactly
     * like every other controller/service in this app resolves route models
     * (see PROJECT_MAP §9 rule 7). This proves the service is safe even if a
     * caller ever bypasses StoreTransferRequest's own friendly existence
     * check — a foreign id 404s (ModelNotFoundException) rather than leaking
     * cross-tenant data.
     */
    public function test_transfer_service_scopes_bank_account_lookups_and_404s_a_foreign_id(): void
    {
        $companyA = Company::factory()->create();
        $foreignAccount = $this->bankAccount($companyA);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        $localAccount = $this->bankAccount($companyB);

        $this->expectException(ModelNotFoundException::class);

        app(CompanyContext::class)->runFor($companyB, function () use ($localAccount, $foreignAccount) {
            app(TransferService::class)->transfer((string) Str::uuid(), [
                'from_bank_account_id' => $localAccount->id,
                'to_bank_account_id' => $foreignAccount->id,
                'transfer_date' => '2026-07-01',
                'amount' => 10000,
                'memo' => 'Cross-tenant attempt',
            ]);
        });

        $this->assertSame(0, Transfer::query()->count());
    }

    // ---------------------------------------------------------------
    // Idempotency
    // ---------------------------------------------------------------

    public function test_double_submit_same_idempotency_key_creates_exactly_one_transfer(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $from = $this->bankAccount($company);
        $to = $this->bankAccount($company);
        $key = (string) Str::uuid();

        $data = [
            'from_bank_account_id' => $from->id,
            'to_bank_account_id' => $to->id,
            'amount' => 25000,
        ];

        $first = $this->postTransfer($data, $key);
        $second = $this->postTransfer($data, $key);

        $first->assertRedirect();
        $second->assertRedirect();

        $this->assertSame(1, Transfer::query()->count());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'transfer')->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'transfer.created')->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Locked periods
    // ---------------------------------------------------------------

    public function test_posting_into_a_locked_period_returns_422_and_persists_nothing(): void
    {
        $company = Company::factory()->create(['books_locked_through' => '2026-06-30']);
        $this->actingAsRole($company, Role::Owner);
        $from = $this->bankAccount($company);
        $to = $this->bankAccount($company);

        $response = $this->postTransfer([
            'from_bank_account_id' => $from->id,
            'to_bank_account_id' => $to->id,
            'amount' => 10000,
            'transfer_date' => '2026-06-15',
        ]);

        $response->assertSessionHasErrors('transfer_date');
        $this->assertStringContainsString('locked', session('errors')->first('transfer_date'));
        $this->assertSame(0, Transfer::query()->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Gate: transfers.create
    // ---------------------------------------------------------------

    public function test_owner_admin_and_accountant_can_create_transfers(): void
    {
        foreach ([Role::Owner, Role::Admin, Role::Accountant] as $role) {
            $company = Company::factory()->create();
            $this->actingAsRole($company, $role);
            $from = $this->bankAccount($company);
            $to = $this->bankAccount($company);

            $this->postTransfer([
                'from_bank_account_id' => $from->id,
                'to_bank_account_id' => $to->id,
                'amount' => 10000,
            ])->assertRedirect();
        }
    }

    public function test_staff_is_forbidden_from_creating_transfers(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);
        $from = $this->bankAccount($company);
        $to = $this->bankAccount($company);

        $this->get('/transfers/new')->assertForbidden();
        $this->postTransfer([
            'from_bank_account_id' => $from->id,
            'to_bank_account_id' => $to->id,
            'amount' => 10000,
        ])->assertForbidden();

        $this->assertSame(0, Transfer::query()->count());
    }

    // ---------------------------------------------------------------
    // Create form options
    // ---------------------------------------------------------------

    public function test_create_lists_only_non_archived_bank_accounts_as_options(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $active = $this->bankAccount($company, ['name' => 'Active']);
        $archived = $this->bankAccount($company, ['name' => 'Archived', 'is_archived' => true]);

        $response = $this->get('/transfers/new');

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) use ($active, $archived) {
            $page->component('Banking/Transfer');
            $page->where('bankAccounts', function ($accounts) use ($active, $archived) {
                $ids = collect($accounts)->pluck('id')->all();

                return in_array($active->id, $ids, true) && ! in_array($archived->id, $ids, true);
            });
        });
    }
}
