<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\EntryRole;
use App\Enums\Role;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\ManualJournal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

class ManualJournalTest extends TestCase
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
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OperatingIncome->value,
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

    private function postJournal(array $lines, array $overrides = [], ?string $key = null): TestResponse
    {
        return $this->postJson('/journal-entries', array_merge([
            'entry_date' => '2026-07-01',
            'memo' => 'Test entry',
            'lines' => $lines,
        ], $overrides), ['X-Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    // ---------------------------------------------------------------
    // Happy path
    // ---------------------------------------------------------------

    public function test_owner_can_post_a_balanced_manual_journal_via_http(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $response = $this->postJournal([
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ], ['memo' => 'Cash sale']);

        $response->assertRedirect(route('journal-entries.index'));

        $journal = ManualJournal::query()->sole();
        $this->assertSame('2026-07-01', $journal->entry_date->toDateString());
        $this->assertSame('Cash sale', $journal->memo);
        $this->assertSame('posted', $journal->status);

        $entry = JournalEntry::query()
            ->where('source_type', 'manual_journal')
            ->where('source_id', $journal->id)
            ->where('entry_role', EntryRole::Primary->value)
            ->sole();
        $this->assertCount(2, $entry->lines);
        $bankLine = $entry->lines->firstWhere('account_id', $bank->id);
        $incomeLine = $entry->lines->firstWhere('account_id', $income->id);
        $this->assertSame(10000, $bankLine->debit);
        $this->assertSame(0, $bankLine->credit);
        $this->assertSame(0, $incomeLine->debit);
        $this->assertSame(10000, $incomeLine->credit);

        $audit = ActivityLog::query()->where('action', 'manual_journal.posted')->sole();
        $this->assertSame($journal->id, $audit->subject_id);
        $this->assertSame($entry->id, $audit->after['entry_id']);
        $this->assertSame('Cash sale', $audit->after['memo']);

        $this->assertLedgerBalances();
    }

    public function test_accountant_role_is_allowed_to_post(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Accountant);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $response = $this->postJournal([
            ['account_id' => $bank->id, 'debit' => 5000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 5000],
        ]);

        $response->assertRedirect(route('journal-entries.index'));
        $this->assertSame(1, ManualJournal::query()->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Balance enforcement
    // ---------------------------------------------------------------

    public function test_unbalanced_manual_journal_returns_422_and_persists_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $response = $this->postJournal([
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 9000],
        ], ['memo' => 'Broken']);

        // This app's exception handler only renders raw JSON for `api/*`
        // routes (see bootstrap/app.php shouldRenderJsonWhen); everywhere
        // else — including this Inertia-driven form — a ValidationException
        // becomes a redirect back with errors flashed to the session.
        $response->assertSessionHasErrors('lines');
        $this->assertSame(0, ManualJournal::query()->count());
        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertLedgerBalances();
    }

    public function test_line_with_both_sides_positive_returns_422_and_persists_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $response = $this->postJournal([
            ['account_id' => $bank->id, 'debit' => 5000, 'credit' => 5000],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 5000],
        ]);

        $response->assertSessionHasErrors('lines.0.debit');
        $this->assertSame(0, ManualJournal::query()->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Role gate (§7.14): staff and admin lack ledger.post_manual
    // ---------------------------------------------------------------

    public function test_staff_role_is_forbidden_from_posting(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $response = $this->postJournal([
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ]);

        $response->assertForbidden();
        $this->assertSame(0, ManualJournal::query()->count());
    }

    public function test_admin_role_is_forbidden_from_posting(): void
    {
        // Per spec §7.14, Admin does NOT hold ledger.post_manual (unlike Owner/Accountant).
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Admin);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $response = $this->postJournal([
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ]);

        $response->assertForbidden();
        $this->assertSame(0, ManualJournal::query()->count());
    }

    // ---------------------------------------------------------------
    // Control accounts
    // ---------------------------------------------------------------

    public function test_control_account_line_is_rejected_with_friendly_message(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $ar = $this->receivableAccount($company);
        $income = $this->incomeAccount($company);

        $response = $this->postJournal([
            ['account_id' => $ar->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ], ['memo' => 'Touching AR']);

        $response->assertSessionHasErrors('lines');
        $message = session('errors')->first('lines');
        $this->assertNotNull($message);
        $this->assertStringContainsString($ar->code, $message);
        $this->assertSame(0, ManualJournal::query()->count());
        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Idempotency
    // ---------------------------------------------------------------

    public function test_double_submit_same_idempotency_key_creates_exactly_one_journal(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $key = (string) Str::uuid();

        $lines = [
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ];

        $first = $this->postJournal($lines, ['memo' => 'Double submit'], $key);
        $second = $this->postJournal($lines, ['memo' => 'Double submit'], $key);

        $first->assertRedirect(route('journal-entries.index'));
        $second->assertRedirect(route('journal-entries.index'));

        $this->assertSame(1, ManualJournal::query()->count());
        $this->assertSame(1, JournalEntry::query()->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'manual_journal.posted')->count());
        $this->assertLedgerBalances();
    }

    public function test_missing_idempotency_key_returns_422(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $response = $this->postJson('/journal-entries', [
            'entry_date' => '2026-07-01',
            'memo' => 'No key',
            'lines' => [
                ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
                ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
            ],
        ]);

        $response->assertSessionHasErrors('idempotency_key');
        $this->assertSame(0, ManualJournal::query()->count());
    }

    public function test_invalid_idempotency_key_format_returns_422(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $response = $this->postJson('/journal-entries', [
            'entry_date' => '2026-07-01',
            'memo' => 'Bad key',
            'lines' => [
                ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
                ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
            ],
        ], ['X-Idempotency-Key' => 'not-a-uuid']);

        $response->assertSessionHasErrors('idempotency_key');
        $this->assertSame(0, ManualJournal::query()->count());
    }

    // ---------------------------------------------------------------
    // Locked periods
    // ---------------------------------------------------------------

    public function test_posting_into_a_locked_period_returns_422_with_lock_message(): void
    {
        $company = Company::factory()->create(['books_locked_through' => '2026-06-30']);
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $response = $this->postJournal([
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ], ['entry_date' => '2026-06-15', 'memo' => 'Locked period']);

        $response->assertSessionHasErrors('lines');
        $this->assertStringContainsString('locked', session('errors')->first('lines'));
        $this->assertSame(0, ManualJournal::query()->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Reversal
    // ---------------------------------------------------------------

    public function test_reverse_produces_mirror_entry_and_marks_status_reversed(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postJournal([
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ], ['memo' => 'Original'])->assertRedirect();

        $journal = ManualJournal::query()->sole();

        $response = $this->postJson("/journal-entries/{$journal->id}/reverse", [
            'date' => '2026-07-05',
            'memo' => 'Reversing original',
        ]);

        $response->assertRedirect(route('journal-entries.index'));

        $journal->refresh();
        $this->assertSame('reversed', $journal->status);

        $entries = JournalEntry::query()
            ->where('source_type', 'manual_journal')
            ->where('source_id', $journal->id)
            ->get();
        $this->assertCount(2, $entries);

        $reversal = $entries->firstWhere('entry_role', EntryRole::Reversal);
        $this->assertNotNull($reversal);
        $this->assertSame('2026-07-05', $reversal->entry_date->toDateString());
        $this->assertSame($entries->firstWhere('entry_role', EntryRole::Primary)->id, $reversal->reverses_entry_id);

        $bankLine = $reversal->lines->firstWhere('account_id', $bank->id);
        $incomeLine = $reversal->lines->firstWhere('account_id', $income->id);
        $this->assertSame(0, $bankLine->debit);
        $this->assertSame(10000, $bankLine->credit);
        $this->assertSame(10000, $incomeLine->debit);
        $this->assertSame(0, $incomeLine->credit);

        $audit = ActivityLog::query()->where('action', 'manual_journal.reversed')->sole();
        $this->assertSame($journal->id, $audit->subject_id);
        $this->assertSame(['reversal_entry_id' => $reversal->id], $audit->after);

        $this->assertLedgerBalances();
    }

    public function test_reversing_twice_returns_422_with_friendly_message(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postJournal([
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ], ['memo' => 'Original'])->assertRedirect();

        $journal = ManualJournal::query()->sole();

        $this->postJson("/journal-entries/{$journal->id}/reverse", [
            'date' => '2026-07-05',
            'memo' => 'First reversal',
        ])->assertRedirect();

        $response = $this->postJson("/journal-entries/{$journal->id}/reverse", [
            'date' => '2026-07-06',
            'memo' => 'Second reversal attempt',
        ]);

        $response->assertSessionHasErrors('memo');
        $this->assertStringContainsString('already been reversed', session('errors')->first('memo'));

        $this->assertSame(2, JournalEntry::query()
            ->where('source_type', 'manual_journal')
            ->where('source_id', $journal->id)
            ->count());
        $this->assertSame('reversed', $journal->fresh()->status);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Index / New pages
    // ---------------------------------------------------------------

    public function test_index_lists_manual_journals_with_their_posted_entries_eager_loaded(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postJournal([
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ], ['memo' => 'For index'])->assertRedirect();

        $response = $this->get('/journal-entries');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Journal/Index')
            ->has('journals.data', 1)
            ->where('journals.data.0.memo', 'For index')
        );
    }

    public function test_new_returns_account_options_excluding_archived_and_control_accounts(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $ar = $this->receivableAccount($company);
        $archivedBank = $this->bankAccount($company, ['code' => '1011', 'is_archived' => true]);

        $response = $this->get('/journal-entries/new');

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) use ($bank, $ar, $archivedBank) {
            $page->component('Journal/New');
            $page->has('accounts', 1);
            $page->where('accounts', function ($accounts) use ($bank, $ar, $archivedBank) {
                $ids = collect($accounts)->pluck('id')->all();

                return in_array($bank->id, $ids, true)
                    && ! in_array($ar->id, $ids, true)
                    && ! in_array($archivedBank->id, $ids, true);
            });
        });
    }
}
