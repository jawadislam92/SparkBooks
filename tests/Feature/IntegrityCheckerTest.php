<?php

namespace Tests\Feature;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\IntegrityRun;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\ManualJournal;
use App\Models\User;
use App\Services\IntegrityChecker;
use App\Services\LedgerService;
use App\Services\OpeningBalanceService;
use App\Services\OtherReceiptService;
use App\Services\TransferService;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * §9 correctness watchdog: App\Services\IntegrityChecker, the
 * `ledger:verify` command (App\Console\Commands\VerifyLedgerIntegrity), and
 * the Settings > Health screen (App\Http\Controllers\HealthController).
 *
 * The property under test throughout is "fails loudly, never passes
 * vacuously" — every corruption scenario is seeded by RAW SQL that bypasses
 * the model guards (BelongsToCompany scoping, ImmutableLedger, etc.), since
 * going through the normal write path (LedgerService/ManualJournalService)
 * would simply refuse to create the corrupt state in the first place.
 */
class IntegrityCheckerTest extends TestCase
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

    private function equityAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '3000',
            'type' => AccountType::Equity->value,
            'subtype' => AccountSubtype::Equity->value,
        ], $overrides));
    }

    /**
     * A BankAccount MODEL (distinct from the plain ledger Account created by
     * bankAccount() above) — the linked-Account nested factory needs a live
     * CompanyContext to fill its own company_id, hence the runFor wrap.
     * Mirrors TransfersTest/OtherReceiptsTest/OpeningBalancesTest.
     */
    private function bankAccountModel(Company $company, array $overrides = []): BankAccount
    {
        return app(CompanyContext::class)->runFor($company, fn () => BankAccount::factory()->create(array_merge([
            'company_id' => $company->id,
        ], $overrides)));
    }

    /**
     * Posts a mirror-image reversal of an existing entry via LedgerService
     * directly — source-type agnostic, exactly like ManualJournalService and
     * OpeningBalanceService's own void path do internally. Used here to
     * reverse transfer/other_receipt-sourced entries too, since those
     * services don't (yet) expose their own reverse() method but the
     * underlying ledger mechanism (same source_type/source_id, entry_role=
     * reversal) is identical regardless of which service originally posted.
     */
    private function reverseEntry(JournalEntry $entry): JournalEntry
    {
        return DB::transaction(fn () => app(LedgerService::class)->reverse(
            $entry, CarbonImmutable::parse('2026-07-02'), 'Test reversal'
        ));
    }

    /**
     * Posts a genuinely balanced entry straight through LedgerService (the
     * one gate into the ledger), with a real ManualJournal document backing
     * it — bypassing HTTP/auth/idempotency entirely, since these tests only
     * care about the ledger state the checker sees afterward. Sets
     * CompanyContext to $company as a side effect (matches how every
     * BelongsToCompany write is scoped).
     *
     * @param  list<array{account_id:int, debit:int, credit:int}>  $lines
     */
    private function postBalancedEntry(Company $company, array $lines, ?int $sourceId = null): JournalEntry
    {
        app(CompanyContext::class)->set($company);

        $doc = ManualJournal::query()->create([
            'entry_date' => '2026-07-01',
            'memo' => 'Test entry',
            'status' => 'posted',
        ]);

        $draft = new EntryDraft(
            entryDate: CarbonImmutable::parse('2026-07-01'),
            memo: 'Test entry',
            sourceType: 'manual_journal',
            sourceId: $sourceId ?? $doc->id,
            lines: array_map(fn ($l) => new LineDraft(
                accountId: $l['account_id'],
                debit: $l['debit'],
                credit: $l['credit'],
            ), $lines),
        );

        return DB::transaction(fn () => app(LedgerService::class)->post($draft));
    }

    /** Raw-inserts a journal_line, bypassing LedgerService/model guards entirely. */
    private function rawInsertLine(Company $company, JournalEntry $entry, Account $account, int $debit, int $credit, ?int $contactId = null): void
    {
        DB::table('journal_lines')->insert([
            'journal_entry_id' => $entry->id,
            'company_id' => $company->id,
            'account_id' => $account->id,
            'debit' => $debit,
            'credit' => $credit,
            'memo' => null,
            'contact_id' => $contactId,
        ]);
    }

    // ---------------------------------------------------------------
    // IntegrityChecker: clean company
    // ---------------------------------------------------------------

    public function test_clean_company_yields_no_findings(): void
    {
        $company = Company::factory()->create();
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postBalancedEntry($company, [
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ]);

        $findings = app(IntegrityChecker::class)->checkCompany($company);

        $this->assertSame([], $findings);
    }

    // ---------------------------------------------------------------
    // IntegrityChecker: seeded corruption
    // ---------------------------------------------------------------

    public function test_raw_tampered_line_produces_entry_balance_and_trial_balance_findings(): void
    {
        $company = Company::factory()->create();
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postBalancedEntry($company, [
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ]);

        // Model guards (JournalLine::booted() throws ImmutableLedger on
        // update) forbid this through Eloquent — raw SQL is the only way to
        // seed this corruption, which is exactly the point of the test.
        $bankLine = JournalLine::query()->where('account_id', $bank->id)->sole();
        DB::statement('UPDATE journal_lines SET debit = ? WHERE id = ?', [15000, $bankLine->id]);

        $findings = app(IntegrityChecker::class)->checkCompany($company);
        $checks = collect($findings)->pluck('check')->all();

        $this->assertContains('entry_balance', $checks);
        $this->assertContains('trial_balance', $checks);

        $balanceFinding = collect($findings)->firstWhere('check', 'entry_balance');
        $this->assertStringContainsString('5000', $balanceFinding['detail']);
    }

    public function test_contactless_control_account_line_produces_finding_without_tripping_balance_checks(): void
    {
        $company = Company::factory()->create();
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $receivable = $this->receivableAccount($company);

        $entry = $this->postBalancedEntry($company, [
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ]);

        // Add an offsetting pair of raw lines so the entry (and the trial
        // balance) still nets to zero — isolating this test to JUST the
        // missing-contact defect, not an incidental balance defect too.
        $this->rawInsertLine($company, $entry, $receivable, debit: 500, credit: 0, contactId: null);
        $this->rawInsertLine($company, $entry, $bank, debit: 0, credit: 500);

        $findings = app(IntegrityChecker::class)->checkCompany($company);
        $checks = collect($findings)->pluck('check')->all();

        $this->assertContains('control_contact', $checks);
        $this->assertNotContains('entry_balance', $checks);
        $this->assertNotContains('trial_balance', $checks);
    }

    public function test_orphaned_manual_journal_source_entry_produces_finding(): void
    {
        $company = Company::factory()->create();

        // A ledger entry claiming to come from a manual journal document
        // that doesn't exist (id 999999). No matching lines are needed —
        // this exercises the orphan_entries check in isolation.
        DB::table('journal_entries')->insert([
            'company_id' => $company->id,
            'entry_date' => '2026-07-01',
            'memo' => 'Orphaned entry (raw insert)',
            'source_type' => 'manual_journal',
            'source_id' => 999999,
            'entry_role' => 'primary',
            'created_by' => null,
        ]);

        $findings = app(IntegrityChecker::class)->checkCompany($company);
        $checks = collect($findings)->pluck('check')->all();

        $this->assertContains('orphan_entries', $checks);
    }

    // ---------------------------------------------------------------
    // IntegrityChecker: Phase 2 source-type-aware orphan check
    // ---------------------------------------------------------------

    /**
     * Raw-inserts a JournalEntry with the given source_type and a source_id
     * (999999) that has no matching row in that type's table, and asserts an
     * orphan_entries finding naming the source_type shows up. Shared by the
     * three Phase 2 source-type tests below — mirrors
     * test_orphaned_manual_journal_source_entry_produces_finding above, one
     * per new source type registered in IntegrityChecker::SOURCE_TABLES.
     */
    private function assertOrphanDetectedForSourceType(string $sourceType): void
    {
        $company = Company::factory()->create();

        DB::table('journal_entries')->insert([
            'company_id' => $company->id,
            'entry_date' => '2026-07-01',
            'memo' => 'Orphaned entry (raw insert)',
            'source_type' => $sourceType,
            'source_id' => 999999,
            'entry_role' => 'primary',
            'created_by' => null,
        ]);

        $findings = app(IntegrityChecker::class)->checkCompany($company);
        $checks = collect($findings)->pluck('check')->all();

        $this->assertContains('orphan_entries', $checks);
        $finding = collect($findings)->firstWhere('check', 'orphan_entries');
        $this->assertStringContainsString($sourceType, $finding['detail']);
    }

    public function test_orphaned_transfer_source_entry_produces_finding(): void
    {
        $this->assertOrphanDetectedForSourceType('transfer');
    }

    public function test_orphaned_other_receipt_source_entry_produces_finding(): void
    {
        $this->assertOrphanDetectedForSourceType('other_receipt');
    }

    public function test_orphaned_opening_balance_run_source_entry_produces_finding(): void
    {
        $this->assertOrphanDetectedForSourceType('opening_balance_run');
    }

    /**
     * The point of the whole extension: a source_type this checker has never
     * heard of (a stand-in for some future Phase 3+ document type someone
     * forgets to register in IntegrityChecker::SOURCE_TABLES) must fail
     * loud — a distinct 'unrecognized_source' finding — rather than being
     * silently skipped by the per-source-type loop.
     */
    public function test_unknown_source_type_produces_unrecognized_source_finding(): void
    {
        $company = Company::factory()->create();

        DB::table('journal_entries')->insert([
            'company_id' => $company->id,
            'entry_date' => '2026-07-01',
            'memo' => 'Future source type (raw insert)',
            'source_type' => 'invoice',
            'source_id' => 1,
            'entry_role' => 'primary',
            'created_by' => null,
        ]);

        $findings = app(IntegrityChecker::class)->checkCompany($company);
        $checks = collect($findings)->pluck('check')->all();

        $this->assertContains('unrecognized_source', $checks);
        $this->assertNotContains('orphan_entries', $checks);
        $finding = collect($findings)->firstWhere('check', 'unrecognized_source');
        $this->assertStringContainsString('invoice', $finding['detail']);
    }

    /**
     * Reversal entries keep the ORIGINAL entry's source_type/source_id (spec
     * §4 iron rule) — they must never be mistaken for orphans (their source
     * row genuinely exists) or for an unrecognized source_type.
     */
    public function test_reversal_of_a_transfer_entry_is_not_flagged_as_orphan_or_unrecognized(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $from = $this->bankAccountModel($company);
        $to = $this->bankAccountModel($company);

        $transfer = app(TransferService::class)->transfer((string) Str::uuid(), [
            'from_bank_account_id' => $from->id,
            'to_bank_account_id' => $to->id,
            'transfer_date' => '2026-07-01',
            'amount' => 5000,
            'memo' => 'Test transfer',
        ]);
        $entry = JournalEntry::query()->where('source_type', 'transfer')->where('source_id', $transfer->id)->sole();
        $this->reverseEntry($entry);

        $findings = app(IntegrityChecker::class)->checkCompany($company);
        $checks = collect($findings)->pluck('check')->all();

        $this->assertNotContains('orphan_entries', $checks);
        $this->assertNotContains('unrecognized_source', $checks);
    }

    /**
     * A company with a live document + reversal pair for EVERY Phase 2
     * source type (manual journal, transfer, other receipt, opening balance
     * run) must come back completely clean — the key assertion being that
     * none of the four reversals gets mistaken for an orphan just because
     * it shares its source_id with a document that ISN'T itself.
     */
    public function test_clean_multi_source_company_with_reversals_yields_no_findings(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);

        $bankA = $this->bankAccountModel($company);
        $bankB = $this->bankAccountModel($company);
        $income = $this->incomeAccount($company);
        $equity = $this->equityAccount($company);
        $obe = $this->equityAccount($company, ['code' => '3100']);

        // manual_journal + reversal
        $mjEntry = $this->postBalancedEntry($company, [
            ['account_id' => $bankA->ledger_account_id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ]);
        $this->reverseEntry($mjEntry);

        // transfer + reversal
        $transfer = app(TransferService::class)->transfer((string) Str::uuid(), [
            'from_bank_account_id' => $bankA->id,
            'to_bank_account_id' => $bankB->id,
            'transfer_date' => '2026-07-01',
            'amount' => 5000,
            'memo' => 'Test transfer',
        ]);
        $transferEntry = JournalEntry::query()->where('source_type', 'transfer')->where('source_id', $transfer->id)->sole();
        $this->reverseEntry($transferEntry);

        // other_receipt + reversal
        $receipt = app(OtherReceiptService::class)->record((string) Str::uuid(), [
            'bank_account_id' => $bankA->id,
            'category_account_id' => $equity->id,
            'receipt_date' => '2026-07-01',
            'amount' => 3000,
            'payer' => 'Test payer',
            'memo' => 'Test receipt',
        ]);
        $receiptEntry = JournalEntry::query()->where('source_type', 'other_receipt')->where('source_id', $receipt->id)->sole();
        $this->reverseEntry($receiptEntry);

        // opening_balance_run + reversal
        $run = app(OpeningBalanceService::class)->apply((string) Str::uuid(), [
            'books_start_date' => '2026-06-01',
            'bank_lines' => [
                ['bank_account_id' => $bankA->id, 'amount' => 20000],
            ],
        ]);
        $runEntry = JournalEntry::query()->where('source_type', 'opening_balance_run')->where('source_id', $run->id)->sole();
        $this->reverseEntry($runEntry);

        $findings = app(IntegrityChecker::class)->checkCompany($company);

        $this->assertSame([], $findings);
    }

    /**
     * Multi-company isolation for the NEW source-type-aware check
     * specifically (the existing isolation test below covers entry_balance).
     * Tampering company A with an orphaned transfer-sourced entry must not
     * leak into company B's findings.
     */
    public function test_orphan_finding_for_a_phase2_source_type_is_isolated_to_the_tampered_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        DB::table('journal_entries')->insert([
            'company_id' => $companyA->id,
            'entry_date' => '2026-07-01',
            'memo' => 'Orphaned entry (raw insert)',
            'source_type' => 'transfer',
            'source_id' => 999999,
            'entry_role' => 'primary',
            'created_by' => null,
        ]);

        $bankB = $this->bankAccount($companyB);
        $incomeB = $this->incomeAccount($companyB);
        $this->postBalancedEntry($companyB, [
            ['account_id' => $bankB->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $incomeB->id, 'debit' => 0, 'credit' => 10000],
        ]);

        $findingsA = app(IntegrityChecker::class)->checkCompany($companyA);
        $findingsB = app(IntegrityChecker::class)->checkCompany($companyB);

        $this->assertContains('orphan_entries', collect($findingsA)->pluck('check')->all());
        $this->assertSame([], $findingsB);
    }

    public function test_checker_reports_every_finding_simultaneously_not_just_the_first(): void
    {
        $company = Company::factory()->create();
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $receivable = $this->receivableAccount($company);

        $entry = $this->postBalancedEntry($company, [
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ]);

        // Break balance...
        $bankLine = JournalLine::query()->where('account_id', $bank->id)->sole();
        DB::statement('UPDATE journal_lines SET debit = ? WHERE id = ?', [15000, $bankLine->id]);

        // ...AND add a contact-less control line...
        $this->rawInsertLine($company, $entry, $receivable, debit: 500, credit: 0, contactId: null);

        // ...AND an orphaned entry.
        DB::table('journal_entries')->insert([
            'company_id' => $company->id,
            'entry_date' => '2026-07-01',
            'memo' => 'Orphaned entry (raw insert)',
            'source_type' => 'manual_journal',
            'source_id' => 999999,
            'entry_role' => 'primary',
            'created_by' => null,
        ]);

        $findings = app(IntegrityChecker::class)->checkCompany($company);
        $checks = collect($findings)->pluck('check')->all();

        $this->assertContains('entry_balance', $checks);
        $this->assertContains('trial_balance', $checks);
        $this->assertContains('control_contact', $checks);
        $this->assertContains('orphan_entries', $checks);
    }

    // ---------------------------------------------------------------
    // ledger:verify command — exit codes and persistence
    // ---------------------------------------------------------------

    public function test_command_exits_zero_and_persists_a_clean_run_when_the_only_company_is_clean(): void
    {
        $company = Company::factory()->create();
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postBalancedEntry($company, [
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ]);

        $this->artisan('ledger:verify')->assertExitCode(0);

        $run = IntegrityRun::query()->withoutGlobalScopes()->where('company_id', $company->id)->sole();
        $this->assertTrue($run->clean);
        $this->assertNull($run->findings);
    }

    public function test_command_exits_nonzero_and_logs_when_there_are_zero_companies(): void
    {
        Log::spy();

        $this->assertSame(0, Company::query()->count());

        $this->artisan('ledger:verify')->assertExitCode(1);

        Log::shouldHaveReceived('error')->atLeast()->once();
        $this->assertSame(0, IntegrityRun::query()->withoutGlobalScopes()->count());
    }

    public function test_command_exits_nonzero_and_persists_findings_for_a_corrupt_company(): void
    {
        Log::spy();

        $company = Company::factory()->create();
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postBalancedEntry($company, [
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ]);

        $bankLine = JournalLine::query()->where('account_id', $bank->id)->sole();
        DB::statement('UPDATE journal_lines SET debit = ? WHERE id = ?', [15000, $bankLine->id]);

        $this->artisan('ledger:verify')->assertExitCode(1);

        $run = IntegrityRun::query()->withoutGlobalScopes()->where('company_id', $company->id)->sole();
        $this->assertFalse($run->clean);
        $checks = collect($run->findings)->pluck('check')->all();
        $this->assertContains('entry_balance', $checks);
        $this->assertContains('trial_balance', $checks);

        Log::shouldHaveReceived('error')->atLeast()->once();
    }

    public function test_command_isolates_findings_to_the_tampered_company_only(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $bankA = $this->bankAccount($companyA);
        $incomeA = $this->incomeAccount($companyA);
        $this->postBalancedEntry($companyA, [
            ['account_id' => $bankA->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $incomeA->id, 'debit' => 0, 'credit' => 10000],
        ]);
        $tamperedLine = JournalLine::query()->where('account_id', $bankA->id)->sole();
        DB::statement('UPDATE journal_lines SET debit = ? WHERE id = ?', [15000, $tamperedLine->id]);

        $bankB = $this->bankAccount($companyB);
        $incomeB = $this->incomeAccount($companyB);
        $this->postBalancedEntry($companyB, [
            ['account_id' => $bankB->id, 'debit' => 20000, 'credit' => 0],
            ['account_id' => $incomeB->id, 'debit' => 0, 'credit' => 20000],
        ]);

        $this->artisan('ledger:verify')->assertExitCode(1);

        $runA = IntegrityRun::query()->withoutGlobalScopes()->where('company_id', $companyA->id)->sole();
        $runB = IntegrityRun::query()->withoutGlobalScopes()->where('company_id', $companyB->id)->sole();

        $this->assertFalse($runA->clean);
        $this->assertNotNull($runA->findings);

        $this->assertTrue($runB->clean);
        $this->assertNull($runB->findings);
    }

    /**
     * Vacuous-scope guard, precondition: a company that genuinely has
     * posted entries must see a non-zero count when queried through its own
     * CompanyContext. This is the invariant the guard in
     * VerifyLedgerIntegrity relies on holding true in the healthy case —
     * without it, the guard's comparison (documents exist vs. entries seen
     * in-context) would be meaningless.
     */
    public function test_a_company_with_posted_entries_yields_nonzero_entries_in_its_own_context(): void
    {
        $company = Company::factory()->create();
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postBalancedEntry($company, [
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ]);

        $count = app(CompanyContext::class)->runFor($company, fn () => JournalEntry::query()->count());

        $this->assertGreaterThan(0, $count);
    }

    /**
     * A genuine (not synthetic) trip of the vacuous-scope guard: a company
     * with a posted ManualJournal document but zero JournalEntry rows at
     * all. The ground-truth check (raw query against manual_journals) says
     * "this company has documents"; the in-context check (JournalEntry::
     * query()->count()) says "zero entries visible" — exactly the silent-
     * scope-returned-nothing failure the guard exists to catch, regardless
     * of whether the root cause is a scoping bug or a data anomaly.
     */
    public function test_vacuous_scope_guard_trips_for_a_company_with_a_document_but_no_ledger_entries(): void
    {
        Log::spy();

        $company = Company::factory()->create();

        DB::table('manual_journals')->insert([
            'company_id' => $company->id,
            'entry_date' => '2026-07-01',
            'memo' => 'Document with no matching ledger entry',
            'status' => 'posted',
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('ledger:verify')->assertExitCode(1);

        $run = IntegrityRun::query()->withoutGlobalScopes()->where('company_id', $company->id)->sole();
        $this->assertFalse($run->clean);
        $checks = collect($run->findings)->pluck('check')->all();
        $this->assertContains('vacuous_scope', $checks);

        Log::shouldHaveReceived('error')->atLeast()->once();
    }

    // ---------------------------------------------------------------
    // Settings > Health screen — gate matrix
    // ---------------------------------------------------------------

    public function test_owner_can_view_health_screen_with_no_run_yet(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/settings/health')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/Health')
                ->where('latestRun', null)
            );
    }

    public function test_owner_sees_the_latest_run_on_the_health_screen(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $this->postBalancedEntry($company, [
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ]);

        $this->post('/settings/health/run')->assertRedirect(route('settings.health'));

        $this->get('/settings/health')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/Health')
                ->where('latestRun.clean', true)
                ->where('latestRun.findings', [])
            );
    }

    public function test_accountant_can_view_health_screen(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Accountant);

        $this->get('/settings/health')->assertOk();
    }

    public function test_admin_cannot_view_health_screen(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Admin);

        $this->get('/settings/health')->assertForbidden();
    }

    public function test_staff_cannot_view_health_screen(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->get('/settings/health')->assertForbidden();
    }

    public function test_staff_cannot_run_the_check(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->post('/settings/health/run')->assertForbidden();
        $this->assertSame(0, IntegrityRun::query()->withoutGlobalScopes()->count());
    }

    // ---------------------------------------------------------------
    // "Run now" — synchronous check + audit log
    // ---------------------------------------------------------------

    public function test_run_now_creates_a_clean_run_and_is_audit_logged(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $this->postBalancedEntry($company, [
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ]);

        $response = $this->post('/settings/health/run');

        $response->assertRedirect(route('settings.health'));

        $run = IntegrityRun::query()->sole();
        $this->assertTrue($run->clean);
        $this->assertNull($run->findings);

        $audit = ActivityLog::query()->where('action', 'integrity.checked')->sole();
        $this->assertSame($run->id, $audit->subject_id);
        $this->assertSame(true, $audit->after['clean']);
    }

    public function test_run_now_surfaces_findings_when_the_company_is_corrupt(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $this->postBalancedEntry($company, [
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ]);
        $bankLine = JournalLine::query()->where('account_id', $bank->id)->sole();
        DB::statement('UPDATE journal_lines SET debit = ? WHERE id = ?', [15000, $bankLine->id]);

        $this->post('/settings/health/run')->assertRedirect(route('settings.health'));

        $run = IntegrityRun::query()->sole();
        $this->assertFalse($run->clean);
        $checks = collect($run->findings)->pluck('check')->all();
        $this->assertContains('entry_balance', $checks);
        $this->assertContains('trial_balance', $checks);

        $audit = ActivityLog::query()->where('action', 'integrity.checked')->sole();
        $this->assertSame(false, $audit->after['clean']);

        $this->get('/settings/health')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/Health')
                ->where('latestRun.clean', false)
            );
    }
}
