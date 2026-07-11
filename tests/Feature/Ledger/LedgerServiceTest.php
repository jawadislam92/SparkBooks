<?php

namespace Tests\Feature\Ledger;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\EntryRole;
use App\Exceptions\Ledger\ArchivedAccount;
use App\Exceptions\Ledger\ControlAccountViolation;
use App\Exceptions\Ledger\EmptyEntry;
use App\Exceptions\Ledger\ImmutableLedger;
use App\Exceptions\Ledger\LockedPeriod;
use App\Exceptions\Ledger\NonPostableAccount;
use App\Exceptions\Ledger\UnbalancedEntry;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\LedgerService;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

class LedgerServiceTest extends TestCase
{
    use AssertsLedgerBalanced;
    use RefreshDatabase;

    private function bankAccount(array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'code' => '1010',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Bank->value,
        ], $overrides));
    }

    private function incomeAccount(array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'code' => '4000',
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OperatingIncome->value,
        ], $overrides));
    }

    private function expenseAccount(array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'code' => '5000',
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
        ], $overrides));
    }

    private function receivableAccount(array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'code' => '1100',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Receivable->value,
        ], $overrides));
    }

    private function payableAccount(array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'code' => '2000',
            'type' => AccountType::Liability->value,
            'subtype' => AccountSubtype::Payable->value,
        ], $overrides));
    }

    // ---------------------------------------------------------------
    // Balanced entries post with exact values
    // ---------------------------------------------------------------

    public function test_balanced_two_line_entry_posts_with_exact_line_values(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount();
        $income = $this->incomeAccount();

        $draft = new EntryDraft(
            entryDate: CarbonImmutable::parse('2026-07-01'),
            memo: 'Cash sale',
            sourceType: 'manual_journal',
            sourceId: 1,
            lines: [
                new LineDraft(accountId: $bank->id, debit: 10000, credit: 0),
                new LineDraft(accountId: $income->id, debit: 0, credit: 10000),
            ],
        );

        $entry = DB::transaction(fn () => app(LedgerService::class)->post($draft));

        $this->assertSame('2026-07-01', $entry->entry_date->toDateString());
        $this->assertSame('Cash sale', $entry->memo);
        $this->assertSame('manual_journal', $entry->source_type);
        $this->assertSame(1, $entry->source_id);
        $this->assertSame(EntryRole::Primary, $entry->entry_role);
        $this->assertNull($entry->reverses_entry_id);
        $this->assertCount(2, $entry->lines);

        $bankLine = $entry->lines->firstWhere('account_id', $bank->id);
        $incomeLine = $entry->lines->firstWhere('account_id', $income->id);
        $this->assertSame(10000, $bankLine->debit);
        $this->assertSame(0, $bankLine->credit);
        $this->assertSame(0, $incomeLine->debit);
        $this->assertSame(10000, $incomeLine->credit);

        $this->assertSame(1, JournalEntry::query()->count());
        $this->assertSame(2, JournalLine::query()->count());
        $this->assertLedgerBalances();
    }

    public function test_balanced_three_line_entry_posts_with_exact_line_values(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount();
        $expense = $this->expenseAccount();
        $income = $this->incomeAccount();

        $draft = new EntryDraft(
            entryDate: CarbonImmutable::parse('2026-07-02'),
            memo: 'Split receipt',
            sourceType: 'manual_journal',
            sourceId: 2,
            lines: [
                new LineDraft(accountId: $bank->id, debit: 6000, credit: 0),
                new LineDraft(accountId: $expense->id, debit: 4000, credit: 0),
                new LineDraft(accountId: $income->id, debit: 0, credit: 10000),
            ],
        );

        $entry = DB::transaction(fn () => app(LedgerService::class)->post($draft));

        $this->assertCount(3, $entry->lines);
        $this->assertSame(6000, $entry->lines->firstWhere('account_id', $bank->id)->debit);
        $this->assertSame(4000, $entry->lines->firstWhere('account_id', $expense->id)->debit);
        $this->assertSame(10000, $entry->lines->firstWhere('account_id', $income->id)->credit);

        $this->assertSame(3, JournalLine::query()->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Rejections that must persist nothing
    // ---------------------------------------------------------------

    public function test_unbalanced_entry_throws_and_persists_nothing(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount();
        $income = $this->incomeAccount();

        $draft = new EntryDraft(
            entryDate: CarbonImmutable::parse('2026-07-01'),
            memo: 'Broken',
            sourceType: 'manual_journal',
            sourceId: 3,
            lines: [
                new LineDraft(accountId: $bank->id, debit: 10000, credit: 0),
                new LineDraft(accountId: $income->id, debit: 0, credit: 9000),
            ],
        );

        try {
            DB::transaction(fn () => app(LedgerService::class)->post($draft));
            $this->fail('Expected UnbalancedEntry to be thrown.');
        } catch (UnbalancedEntry) {
            // expected
        }

        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertSame(0, JournalLine::query()->count());
        $this->assertLedgerBalances();
    }

    public function test_line_with_both_sides_zero_throws_unbalanced_entry(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount();
        $income = $this->incomeAccount();

        $draft = new EntryDraft(
            entryDate: CarbonImmutable::parse('2026-07-01'),
            memo: 'Zero line',
            sourceType: 'manual_journal',
            sourceId: 4,
            lines: [
                new LineDraft(accountId: $bank->id, debit: 0, credit: 0),
                new LineDraft(accountId: $income->id, debit: 0, credit: 10000),
            ],
        );

        try {
            DB::transaction(fn () => app(LedgerService::class)->post($draft));
            $this->fail('Expected UnbalancedEntry to be thrown.');
        } catch (UnbalancedEntry) {
            // expected
        }

        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertLedgerBalances();
    }

    public function test_line_with_both_sides_positive_throws_unbalanced_entry(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount();
        $income = $this->incomeAccount();

        $draft = new EntryDraft(
            entryDate: CarbonImmutable::parse('2026-07-01'),
            memo: 'Both positive',
            sourceType: 'manual_journal',
            sourceId: 5,
            lines: [
                new LineDraft(accountId: $bank->id, debit: 5000, credit: 5000),
                new LineDraft(accountId: $income->id, debit: 0, credit: 5000),
            ],
        );

        try {
            DB::transaction(fn () => app(LedgerService::class)->post($draft));
            $this->fail('Expected UnbalancedEntry to be thrown.');
        } catch (UnbalancedEntry) {
            // expected
        }

        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertLedgerBalances();
    }

    public function test_empty_lines_throws_empty_entry(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);

        $draft = new EntryDraft(
            entryDate: CarbonImmutable::parse('2026-07-01'),
            memo: 'Nothing',
            sourceType: 'manual_journal',
            sourceId: 6,
            lines: [],
        );

        try {
            DB::transaction(fn () => app(LedgerService::class)->post($draft));
            $this->fail('Expected EmptyEntry to be thrown.');
        } catch (EmptyEntry) {
            // expected
        }

        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Transaction-ownership guard
    // ---------------------------------------------------------------

    public function test_posting_outside_a_transaction_throws_logic_exception(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount();
        $income = $this->incomeAccount();

        $draft = new EntryDraft(
            entryDate: CarbonImmutable::parse('2026-07-01'),
            memo: 'Should never post',
            sourceType: 'manual_journal',
            sourceId: 7,
            lines: [
                new LineDraft(accountId: $bank->id, debit: 10000, credit: 0),
                new LineDraft(accountId: $income->id, debit: 0, credit: 10000),
            ],
        );

        // RefreshDatabase already wraps this test in a transaction (level >= 1),
        // so LedgerService::post()'s guard would never see level 0 unless we
        // genuinely drop out of it. Commit the wrapping transaction(s) so the
        // guard is exercised for real, not just asserted structurally.
        while (DB::transactionLevel() > 0) {
            DB::commit();
        }
        $this->assertSame(0, DB::transactionLevel());

        try {
            $this->expectException(LogicException::class);
            app(LedgerService::class)->post($draft);
        } finally {
            // We bypassed RefreshDatabase's rollback for this test, so the
            // company/account rows we created were committed for real —
            // clean them up ourselves and reopen a transaction so teardown
            // behaves normally for the rest of the suite.
            DB::table('accounts')->where('company_id', $company->id)->delete();
            DB::table('companies')->where('id', $company->id)->delete();
            DB::beginTransaction();
        }
        // No assertion past the try/finally: the LogicException from post()
        // propagates out (expectException catches it at the test boundary), so
        // any line here would be unreachable.
    }

    // ---------------------------------------------------------------
    // Account-state guards
    // ---------------------------------------------------------------

    public function test_primary_entry_touching_archived_account_throws_archived_account(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        // A plain (non-control) archived account: proves the archived guard is its
        // own concern, not conflated with the control-account rule.
        $bank = $this->bankAccount(['is_archived' => true]);
        $income = $this->incomeAccount();

        $draft = new EntryDraft(
            entryDate: CarbonImmutable::parse('2026-07-01'),
            memo: 'Archived',
            sourceType: 'manual_journal',
            sourceId: 8,
            lines: [
                new LineDraft(accountId: $bank->id, debit: 10000, credit: 0),
                new LineDraft(accountId: $income->id, debit: 0, credit: 10000),
            ],
        );

        try {
            DB::transaction(fn () => app(LedgerService::class)->post($draft));
            $this->fail('Expected ArchivedAccount to be thrown.');
        } catch (ControlAccountViolation) {
            $this->fail('A non-control archived account must not throw ControlAccountViolation.');
        } catch (ArchivedAccount $e) {
            $this->assertStringContainsString('archived', $e->getMessage());
            $this->assertStringContainsString($bank->code, $e->getMessage());
        }

        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Sub-accounts: leaf-only posting, enforcement #1 of 3 (Phase 7 Task 1)
    // ---------------------------------------------------------------

    public function test_primary_entry_touching_non_postable_account_throws_non_postable_account(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $parent = $this->bankAccount(['is_postable' => false]);
        $income = $this->incomeAccount();

        $draft = new EntryDraft(
            entryDate: CarbonImmutable::parse('2026-07-01'),
            memo: 'Posting to a parent account',
            sourceType: 'manual_journal',
            sourceId: 24,
            lines: [
                new LineDraft(accountId: $parent->id, debit: 10000, credit: 0),
                new LineDraft(accountId: $income->id, debit: 0, credit: 10000),
            ],
        );

        try {
            DB::transaction(fn () => app(LedgerService::class)->post($draft));
            $this->fail('Expected NonPostableAccount to be thrown.');
        } catch (NonPostableAccount $e) {
            $this->assertStringContainsString($parent->code, $e->getMessage());
        }

        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertLedgerBalances();
    }

    public function test_postable_leaf_account_posts_normally(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount(['is_postable' => true]);
        $income = $this->incomeAccount();

        $draft = new EntryDraft(
            entryDate: CarbonImmutable::parse('2026-07-01'),
            memo: 'Postable leaf',
            sourceType: 'manual_journal',
            sourceId: 25,
            lines: [
                new LineDraft(accountId: $bank->id, debit: 10000, credit: 0),
                new LineDraft(accountId: $income->id, debit: 0, credit: 10000),
            ],
        );

        $entry = DB::transaction(fn () => app(LedgerService::class)->post($draft));

        $this->assertSame(1, JournalEntry::query()->count());
        $this->assertNotNull($entry->id);
        $this->assertLedgerBalances();
    }

    public function test_entry_can_be_reversed_after_its_account_is_archived(): void
    {
        // The load-bearing case: an entry posts while its account is active, the
        // account is later archived, and the entry must STILL be reversible —
        // reversal is the only correction path for immutable entries.
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount();
        $income = $this->incomeAccount();

        $original = DB::transaction(fn () => app(LedgerService::class)->post(new EntryDraft(
            entryDate: CarbonImmutable::parse('2026-07-01'),
            memo: 'Original before archive',
            sourceType: 'manual_journal',
            sourceId: 23,
            lines: [
                new LineDraft(accountId: $bank->id, debit: 10000, credit: 0),
                new LineDraft(accountId: $income->id, debit: 0, credit: 10000),
            ],
        )));

        // Archive both accounts AFTER the entry posted.
        $bank->update(['is_archived' => true]);
        $income->update(['is_archived' => true]);

        $reversal = DB::transaction(
            fn () => app(LedgerService::class)->reverse($original, CarbonImmutable::parse('2026-07-05'), 'Reversal despite archive')
        );

        $this->assertSame(EntryRole::Reversal, $reversal->entry_role);
        $this->assertSame($original->id, $reversal->reverses_entry_id);
        $this->assertCount(2, $reversal->lines);
        $this->assertSame(0, $reversal->lines->firstWhere('account_id', $bank->id)->debit);
        $this->assertSame(10000, $reversal->lines->firstWhere('account_id', $bank->id)->credit);
        $this->assertSame(2, JournalEntry::query()->count());
        $this->assertLedgerBalances();
    }

    public function test_manual_journal_touching_control_account_throws(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ar = $this->receivableAccount();
        $income = $this->incomeAccount();

        $draft = new EntryDraft(
            entryDate: CarbonImmutable::parse('2026-07-01'),
            memo: 'Manual journal touching AR',
            sourceType: 'manual_journal',
            sourceId: 9,
            lines: [
                new LineDraft(accountId: $ar->id, debit: 10000, credit: 0, contactId: 42),
                new LineDraft(accountId: $income->id, debit: 0, credit: 10000),
            ],
        );

        try {
            DB::transaction(fn () => app(LedgerService::class)->post($draft));
            $this->fail('Expected ControlAccountViolation to be thrown.');
        } catch (ControlAccountViolation $e) {
            $this->assertStringContainsString('Manual journal', $e->getMessage());
        }

        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertLedgerBalances();
    }

    public function test_control_account_line_without_contact_throws(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ar = $this->receivableAccount();
        $income = $this->incomeAccount();

        $draft = new EntryDraft(
            entryDate: CarbonImmutable::parse('2026-07-01'),
            memo: 'Invoice without contact',
            sourceType: 'invoice',
            sourceId: 10,
            lines: [
                new LineDraft(accountId: $ar->id, debit: 10000, credit: 0),
                new LineDraft(accountId: $income->id, debit: 0, credit: 10000),
            ],
        );

        try {
            DB::transaction(fn () => app(LedgerService::class)->post($draft));
            $this->fail('Expected ControlAccountViolation to be thrown.');
        } catch (ControlAccountViolation $e) {
            $this->assertStringContainsString('require a contact', $e->getMessage());
        }

        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertLedgerBalances();
    }

    public function test_control_account_line_with_contact_posts(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ar = $this->receivableAccount();
        $income = $this->incomeAccount();
        // journal_lines.contact_id now carries a real FK (composite on
        // contact_id + company_id, added in Phase 2) — it must reference an
        // actual same-company Contact row, not an arbitrary integer.
        $contact = Contact::factory()->create(['company_id' => $company->id]);

        $draft = new EntryDraft(
            entryDate: CarbonImmutable::parse('2026-07-01'),
            memo: 'Invoice with contact',
            sourceType: 'invoice',
            sourceId: 11,
            lines: [
                new LineDraft(accountId: $ar->id, debit: 10000, credit: 0, contactId: $contact->id),
                new LineDraft(accountId: $income->id, debit: 0, credit: 10000),
            ],
        );

        $entry = DB::transaction(fn () => app(LedgerService::class)->post($draft));

        $this->assertCount(2, $entry->lines);
        $arLine = $entry->lines->firstWhere('account_id', $ar->id);
        $this->assertSame($contact->id, $arLine->contact_id);
        $this->assertLedgerBalances();
    }

    public function test_foreign_company_account_in_draft_throws_model_not_found(): void
    {
        [$a, $b] = Company::factory()->count(2)->create();
        $ctx = app(CompanyContext::class);

        $foreignAccount = $ctx->runFor($b, fn () => $this->bankAccount());

        $ctx->set($a);
        $income = $this->incomeAccount();

        $draft = new EntryDraft(
            entryDate: CarbonImmutable::parse('2026-07-01'),
            memo: 'Cross-tenant account',
            sourceType: 'manual_journal',
            sourceId: 12,
            lines: [
                new LineDraft(accountId: $foreignAccount->id, debit: 10000, credit: 0),
                new LineDraft(accountId: $income->id, debit: 0, credit: 10000),
            ],
        );

        $this->expectException(ModelNotFoundException::class);
        DB::transaction(fn () => app(LedgerService::class)->post($draft));
    }

    // ---------------------------------------------------------------
    // Locked periods
    // ---------------------------------------------------------------

    public function test_locked_period_rejects_entry_on_locked_boundary_date(): void
    {
        $company = Company::factory()->create(['books_locked_through' => '2026-06-30']);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount();
        $income = $this->incomeAccount();

        $draft = new EntryDraft(
            entryDate: CarbonImmutable::parse('2026-06-30'),
            memo: 'On the boundary',
            sourceType: 'manual_journal',
            sourceId: 13,
            lines: [
                new LineDraft(accountId: $bank->id, debit: 10000, credit: 0),
                new LineDraft(accountId: $income->id, debit: 0, credit: 10000),
            ],
        );

        try {
            DB::transaction(fn () => app(LedgerService::class)->post($draft));
            $this->fail('Expected LockedPeriod to be thrown.');
        } catch (LockedPeriod) {
            // expected
        }

        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertLedgerBalances();
    }

    public function test_entry_the_day_after_locked_period_posts(): void
    {
        $company = Company::factory()->create(['books_locked_through' => '2026-06-30']);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount();
        $income = $this->incomeAccount();

        $draft = new EntryDraft(
            entryDate: CarbonImmutable::parse('2026-07-01'),
            memo: 'Day after lock',
            sourceType: 'manual_journal',
            sourceId: 14,
            lines: [
                new LineDraft(accountId: $bank->id, debit: 10000, credit: 0),
                new LineDraft(accountId: $income->id, debit: 0, credit: 10000),
            ],
        );

        $entry = DB::transaction(fn () => app(LedgerService::class)->post($draft));

        $this->assertSame(1, JournalEntry::query()->count());
        $this->assertNotNull($entry->id);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Immutability
    // ---------------------------------------------------------------

    private function postSimpleEntry(int $sourceId): JournalEntry
    {
        $bank = $this->bankAccount();
        $income = $this->incomeAccount();

        $draft = new EntryDraft(
            entryDate: CarbonImmutable::parse('2026-07-01'),
            memo: 'Immutability fixture',
            sourceType: 'manual_journal',
            sourceId: $sourceId,
            lines: [
                new LineDraft(accountId: $bank->id, debit: 10000, credit: 0),
                new LineDraft(accountId: $income->id, debit: 0, credit: 10000),
            ],
        );

        return DB::transaction(fn () => app(LedgerService::class)->post($draft));
    }

    public function test_updating_a_posted_journal_entry_throws_immutable_ledger(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $entry = $this->postSimpleEntry(15);
        $this->assertLedgerBalances();

        $this->expectException(ImmutableLedger::class);
        $entry->update(['memo' => 'changed']);
    }

    public function test_deleting_a_posted_journal_entry_throws_immutable_ledger(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $entry = $this->postSimpleEntry(16);
        $this->assertLedgerBalances();

        $this->expectException(ImmutableLedger::class);
        $entry->delete();
    }

    public function test_updating_a_posted_journal_line_throws_immutable_ledger(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $entry = $this->postSimpleEntry(17);
        $this->assertLedgerBalances();

        $line = $entry->lines->first();
        $this->expectException(ImmutableLedger::class);
        $line->update(['memo' => 'changed']);
    }

    public function test_deleting_a_posted_journal_line_throws_immutable_ledger(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $entry = $this->postSimpleEntry(18);
        $this->assertLedgerBalances();

        $line = $entry->lines->first();
        $this->expectException(ImmutableLedger::class);
        $line->delete();
    }

    // ---------------------------------------------------------------
    // Raw DB backstops (CHECK constraint + unique index)
    // ---------------------------------------------------------------

    public function test_db_check_constraint_rejects_raw_both_sided_insert(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $entry = $this->postSimpleEntry(19);
        $bank = Account::query()->where('code', '1010')->sole();
        $this->assertLedgerBalances();

        $this->expectException(QueryException::class);
        DB::table('journal_lines')->insert([
            'journal_entry_id' => $entry->id,
            'company_id' => $company->id,
            'account_id' => $bank->id,
            'debit' => 100,
            'credit' => 50,
            'memo' => null,
            'contact_id' => null,
        ]);
    }

    public function test_db_unique_index_rejects_duplicate_source_primary_insert(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $this->postSimpleEntry(20);
        $this->assertLedgerBalances();

        $this->expectException(QueryException::class);
        DB::table('journal_entries')->insert([
            'company_id' => $company->id,
            'entry_date' => '2026-07-01',
            'memo' => 'Duplicate primary for same source',
            'source_type' => 'manual_journal',
            'source_id' => 20,
            'entry_role' => 'primary',
            'reverses_entry_id' => null,
            'created_by' => null,
            'posted_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // Reversal
    // ---------------------------------------------------------------

    public function test_reverse_produces_mirror_lines_and_links_reverses_entry_id(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount();
        $income = $this->incomeAccount();

        $original = DB::transaction(fn () => app(LedgerService::class)->post(new EntryDraft(
            entryDate: CarbonImmutable::parse('2026-07-01'),
            memo: 'Original',
            sourceType: 'manual_journal',
            sourceId: 21,
            lines: [
                new LineDraft(accountId: $bank->id, debit: 10000, credit: 0),
                new LineDraft(accountId: $income->id, debit: 0, credit: 10000),
            ],
        )));

        $reversal = DB::transaction(
            fn () => app(LedgerService::class)->reverse($original, CarbonImmutable::parse('2026-07-02'), 'Reversing original')
        );

        $this->assertSame(EntryRole::Reversal, $reversal->entry_role);
        $this->assertSame($original->id, $reversal->reverses_entry_id);
        $this->assertSame('manual_journal', $reversal->source_type);
        $this->assertSame(21, $reversal->source_id);
        $this->assertCount(2, $reversal->lines);

        $bankLine = $reversal->lines->firstWhere('account_id', $bank->id);
        $incomeLine = $reversal->lines->firstWhere('account_id', $income->id);
        $this->assertSame(0, $bankLine->debit);
        $this->assertSame(10000, $bankLine->credit);
        $this->assertSame(10000, $incomeLine->debit);
        $this->assertSame(0, $incomeLine->credit);

        $this->assertSame(2, JournalEntry::query()->count());
        $this->assertLedgerBalances();
    }

    /**
     * Phase 7 Task 3 (visible entry numbers): a reversal is a normal
     * LedgerService::post() call under the hood, so it gets its OWN
     * gap-free entry_number exactly like any other entry (never reuses or
     * decrements the original's) -- and the link back to the original,
     * already carried by reverses_entry_id, is surfaced through the new
     * reversesEntry() relation so a caller can read the ORIGINAL's number
     * off the reversal without a second lookup by hand.
     */
    public function test_reverse_gets_its_own_entry_number_and_surfaces_the_originals_number(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount();
        $income = $this->incomeAccount();

        $original = DB::transaction(fn () => app(LedgerService::class)->post(new EntryDraft(
            entryDate: CarbonImmutable::parse('2026-07-01'),
            memo: 'Original',
            sourceType: 'manual_journal',
            sourceId: 26,
            lines: [
                new LineDraft(accountId: $bank->id, debit: 10000, credit: 0),
                new LineDraft(accountId: $income->id, debit: 0, credit: 10000),
            ],
        )));

        $reversal = DB::transaction(
            fn () => app(LedgerService::class)->reverse($original, CarbonImmutable::parse('2026-07-02'), 'Reversing original')
        );

        $this->assertSame(1, $original->entry_number);
        $this->assertSame(2, $reversal->entry_number);
        $this->assertNotSame($original->entry_number, $reversal->entry_number);

        // The reversal's own reversesEntry() relation resolves back to the
        // original row, whose entry_number/displayNumber() is the one the
        // reversal's display should note ("reverses JE-000001").
        $reversal->load('reversesEntry');
        $this->assertSame($original->id, $reversal->reversesEntry->id);
        $this->assertSame($original->entry_number, $reversal->reversesEntry->entry_number);
        $this->assertSame('JE-000001', $reversal->reversesEntry->displayNumber());
        $this->assertSame('JE-000002', $reversal->displayNumber());
        $this->assertLedgerBalances();
    }

    public function test_second_reverse_of_same_entry_throws_query_exception_from_unique_index(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount();
        $income = $this->incomeAccount();

        $original = DB::transaction(fn () => app(LedgerService::class)->post(new EntryDraft(
            entryDate: CarbonImmutable::parse('2026-07-01'),
            memo: 'Original',
            sourceType: 'manual_journal',
            sourceId: 22,
            lines: [
                new LineDraft(accountId: $bank->id, debit: 10000, credit: 0),
                new LineDraft(accountId: $income->id, debit: 0, credit: 10000),
            ],
        )));

        DB::transaction(
            fn () => app(LedgerService::class)->reverse($original, CarbonImmutable::parse('2026-07-02'), 'First reversal')
        );
        $this->assertLedgerBalances();

        $this->expectException(QueryException::class);
        DB::transaction(
            fn () => app(LedgerService::class)->reverse($original, CarbonImmutable::parse('2026-07-03'), 'Second reversal attempt')
        );
    }
}
