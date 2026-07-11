<?php

namespace Tests\Feature;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\DocumentNumberService;
use App\Services\EntryNumberBackfiller;
use App\Services\LedgerService;
use App\Services\Reports\TrialBalanceReport;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

/**
 * Phase 7 Task 3 (visible entry numbers): a human-readable, gap-free,
 * per-company sequential number on every journal entry — DISTINCT from the
 * DB id (JournalEntry::displayNumber(), e.g. "JE-000001").
 *
 * Coverage split (mirrors DocumentNumberTest's own split for the identical
 * counter machinery): THIS file covers the numbering scheme itself
 * (sequencing, per-company scoping, the migration backfill, and the
 * FOR-UPDATE lock proof). LedgerServiceTest covers a reversal getting its
 * own number + referencing the original. GeneralLedgerReportTest covers the
 * number surfacing in the report payload.
 */
class EntryNumberTest extends TestCase
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

    private function postEntry(int $sourceId, string $date = '2026-07-01'): JournalEntry
    {
        $bank = Account::query()->where('code', '1010')->first() ?? $this->bankAccount();
        $income = Account::query()->where('code', '4000')->first() ?? $this->incomeAccount();

        return DB::transaction(fn () => app(LedgerService::class)->post(new EntryDraft(
            entryDate: CarbonImmutable::parse($date),
            memo: 'Entry '.$sourceId,
            sourceType: 'manual_journal',
            sourceId: $sourceId,
            lines: [
                new LineDraft(accountId: $bank->id, debit: 1000, credit: 0),
                new LineDraft(accountId: $income->id, debit: 0, credit: 1000),
            ],
        )));
    }

    // ---------------------------------------------------------------
    // Gap-free, per-company sequencing on real posts
    // ---------------------------------------------------------------

    public function test_posted_entries_get_gap_free_sequential_numbers_per_company(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $this->bankAccount();
        $this->incomeAccount();

        $first = $this->postEntry(1);
        $second = $this->postEntry(2);
        $third = $this->postEntry(3);

        $this->assertSame(1, $first->entry_number);
        $this->assertSame(2, $second->entry_number);
        $this->assertSame(3, $third->entry_number);
        $this->assertSame('JE-000001', $first->displayNumber());
        $this->assertSame('JE-000002', $second->displayNumber());
        $this->assertSame('JE-000003', $third->displayNumber());
        $this->assertLedgerBalances();
    }

    public function test_second_company_has_its_own_independent_sequence(): void
    {
        $companyA = Company::factory()->create();
        app(CompanyContext::class)->set($companyA);
        $this->bankAccount();
        $this->incomeAccount();
        $this->postEntry(1);
        $this->postEntry(2);

        $companyB = Company::factory()->create();
        app(CompanyContext::class)->set($companyB);
        $this->bankAccount();
        $this->incomeAccount();
        $bFirst = $this->postEntry(1);

        // Company B's sequence starts fresh at 1 -- company A's activity
        // must not have bumped it, exactly like DocumentNumberService's
        // existing per-company counter isolation.
        $this->assertSame(1, $bFirst->entry_number);
    }

    public function test_entry_numbers_are_unique_per_company(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $this->bankAccount();
        $this->incomeAccount();
        $this->postEntry(1);
        $this->postEntry(2);

        $numbers = JournalEntry::query()->pluck('entry_number')->all();
        $this->assertSame($numbers, array_unique($numbers));
    }

    // ---------------------------------------------------------------
    // Concurrency-shaped proof: the counter row backing entry numbers is
    // read with a locking SELECT (mirrors DocumentNumberTest::
    // test_counter_read_uses_a_locking_select — the in-process row-lock
    // proof; the true cross-process probe was Phase-3-only, not rebuilt
    // here per the brief).
    // ---------------------------------------------------------------

    public function test_entry_number_allocation_uses_a_locking_select_on_the_counter_row(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount();
        $income = $this->incomeAccount();

        DB::enableQueryLog();
        DB::transaction(fn () => app(LedgerService::class)->post(new EntryDraft(
            entryDate: CarbonImmutable::parse('2026-07-01'),
            memo: 'Locking proof',
            sourceType: 'manual_journal',
            sourceId: 99,
            lines: [
                new LineDraft(accountId: $bank->id, debit: 1000, credit: 0),
                new LineDraft(accountId: $income->id, debit: 0, credit: 1000),
            ],
        )));
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertTrue(
            $queries->contains(fn (string $q) => str_contains(strtolower($q), 'from `document_counters`')
                && str_contains(strtolower($q), 'for update')),
            'Entry-number allocation inside LedgerService::post must read the counter row with a locking (FOR UPDATE) select.'
        );
    }

    // ---------------------------------------------------------------
    // Migration backfill: deterministic, gap-free, per-company, ordered by
    // (entry_date, id) -- exercised directly against EntryNumberBackfiller
    // (the exact code the migration calls) on synthetic out-of-order rows,
    // since RefreshDatabase migrates onto an empty table so the migration
    // itself never sees pre-existing data in tests.
    // ---------------------------------------------------------------

    /** Inserts a raw journal_entries row (bypassing LedgerService) with entry_number left NULL, as a pre-Phase-7-Task-3 row would be. */
    private function insertRawEntry(Company $company, string $entryDate, int $sourceId): int
    {
        return (int) DB::table('journal_entries')->insertGetId([
            'company_id' => $company->id,
            'entry_date' => $entryDate,
            'memo' => 'Legacy entry',
            'source_type' => 'manual_journal',
            'source_id' => $sourceId,
            'entry_role' => 'primary',
            'reverses_entry_id' => null,
            'entry_number' => null,
            'created_by' => null,
            'posted_at' => now(),
        ]);
    }

    public function test_backfill_assigns_gap_free_numbers_ordered_by_entry_date_then_id_per_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        // Inserted out of date order: the LATER-dated row is inserted
        // first (so it gets the LOWER id) -- proving the backfill orders by
        // entry_date, not insertion/id order.
        $laterDateLowerId = $this->insertRawEntry($companyA, '2026-07-10', 100);
        $earlierDateHigherId = $this->insertRawEntry($companyA, '2026-07-01', 101);
        // Same date as the previous row, inserted after it -- proving the
        // tie-break is by id when dates are equal.
        $sameDateEvenHigherId = $this->insertRawEntry($companyA, '2026-07-01', 102);

        // A second company's own row must get its own sequence starting at 1,
        // independent of company A's count.
        $companyBRow = $this->insertRawEntry($companyB, '2026-07-05', 200);

        app(EntryNumberBackfiller::class)->backfill();

        $numberOf = fn (int $id): ?int => JournalEntry::query()
            ->withoutGlobalScopes()
            ->whereKey($id)
            ->value('entry_number');

        $this->assertSame(1, $numberOf($earlierDateHigherId)); // earliest date
        $this->assertSame(2, $numberOf($sameDateEvenHigherId)); // same date, higher id than the next assertion's row
        $this->assertSame(3, $numberOf($laterDateLowerId)); // latest date, despite the lowest id
        $this->assertSame(1, $numberOf($companyBRow)); // company B's own independent sequence
    }

    public function test_backfill_seeds_the_je_counter_so_the_next_post_continues_without_gap_or_collision(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $this->insertRawEntry($company, '2026-07-01', 100);
        $this->insertRawEntry($company, '2026-07-02', 101);
        $this->insertRawEntry($company, '2026-07-03', 102);

        app(EntryNumberBackfiller::class)->backfill();

        // The counter must now be primed at 4 (3 backfilled rows + 1) --
        // reading it via the SAME service the real posting path uses proves
        // there's no gap and no collision with the 3 backfilled numbers.
        $next = DB::transaction(fn () => app(DocumentNumberService::class)->nextNumber('JE'));
        $this->assertSame(4, $next);

        // And a real post through LedgerService picks up right where the
        // backfill left off.
        $this->bankAccount();
        $this->incomeAccount();
        $posted = $this->postEntry(103, '2026-07-04');
        $this->assertSame(5, $posted->entry_number);
    }

    // ---------------------------------------------------------------
    // Additive only: entry numbers must not change any posting amount or
    // the trial balance.
    // ---------------------------------------------------------------

    public function test_entry_numbers_do_not_change_posting_amounts_or_the_trial_balance(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount();
        $income = $this->incomeAccount();

        DB::transaction(fn () => app(LedgerService::class)->post(new EntryDraft(
            entryDate: CarbonImmutable::parse('2026-07-01'),
            memo: 'Cash sale',
            sourceType: 'manual_journal',
            sourceId: 1,
            lines: [
                new LineDraft(accountId: $bank->id, debit: 25000, credit: 0),
                new LineDraft(accountId: $income->id, debit: 0, credit: 25000),
            ],
        )));

        $trialBalance = app(TrialBalanceReport::class)->build(CarbonImmutable::parse('2026-07-31'));

        $this->assertSame(25000, $trialBalance['total_debit']);
        $this->assertSame(25000, $trialBalance['total_credit']);
        $this->assertLedgerBalances();
    }
}
