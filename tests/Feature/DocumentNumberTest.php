<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\DocumentNumberService;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class DocumentNumberTest extends TestCase
{
    use RefreshDatabase;

    private function service(): DocumentNumberService
    {
        return app(DocumentNumberService::class);
    }

    // ---------------------------------------------------------------
    // Gap-free, duplicate-free sequencing
    // ---------------------------------------------------------------

    public function test_next_returns_gap_free_sequential_numbers(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);

        $first = DB::transaction(fn () => $this->service()->next('INV', 'INV'));
        $second = DB::transaction(fn () => $this->service()->next('INV', 'INV'));
        $third = DB::transaction(fn () => $this->service()->next('INV', 'INV'));

        $this->assertSame('INV-0001', $first);
        $this->assertSame('INV-0002', $second);
        $this->assertSame('INV-0003', $third);
    }

    public function test_two_series_are_independent(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);

        $inv1 = DB::transaction(fn () => $this->service()->next('INV', 'INV'));
        $bill1 = DB::transaction(fn () => $this->service()->next('BILL', 'BILL'));
        $inv2 = DB::transaction(fn () => $this->service()->next('INV', 'INV'));

        $this->assertSame('INV-0001', $inv1);
        $this->assertSame('BILL-0001', $bill1);
        $this->assertSame('INV-0002', $inv2);
    }

    public function test_foreign_company_counter_is_isolated(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        app(CompanyContext::class)->set($companyA);
        $aFirst = DB::transaction(fn () => $this->service()->next('INV', 'INV'));
        $aSecond = DB::transaction(fn () => $this->service()->next('INV', 'INV'));

        app(CompanyContext::class)->set($companyB);
        $bFirst = DB::transaction(fn () => $this->service()->next('INV', 'INV'));

        $this->assertSame('INV-0001', $aFirst);
        $this->assertSame('INV-0002', $aSecond);
        // Company B's own counter starts fresh at 1 — company A's activity
        // must not have bumped it.
        $this->assertSame('INV-0001', $bFirst);
    }

    // ---------------------------------------------------------------
    // Transaction guard
    // ---------------------------------------------------------------

    public function test_next_throws_outside_a_transaction(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);

        // RefreshDatabase already wraps this test in a transaction (level >=
        // 1), so the guard would never see level 0 unless we genuinely drop
        // out of it. Commit the wrapping transaction(s) so the guard is
        // exercised for real — mirrors LedgerServiceTest's equivalent test.
        while (DB::transactionLevel() > 0) {
            DB::commit();
        }
        $this->assertSame(0, DB::transactionLevel());

        try {
            $this->expectException(LogicException::class);
            $this->service()->next('INV', 'INV');
        } finally {
            // We bypassed RefreshDatabase's rollback for this test, so the
            // company row was committed for real — clean it up ourselves and
            // reopen a transaction so teardown behaves normally for the rest
            // of the suite.
            DB::table('document_counters')->where('company_id', $company->id)->delete();
            DB::table('companies')->where('id', $company->id)->delete();
            DB::beginTransaction();
        }
        // No assertion past the try/finally: the LogicException from next()
        // propagates out (expectException catches it at the test boundary),
        // so any line here would be unreachable.
    }

    // ---------------------------------------------------------------
    // Concurrency: the counter row is read with a locking SELECT
    // ---------------------------------------------------------------

    public function test_counter_read_uses_a_locking_select(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);

        DB::enableQueryLog();
        DB::transaction(fn () => $this->service()->next('INV', 'INV'));
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertTrue(
            $queries->contains(fn (string $q) => str_contains(strtolower($q), 'from `document_counters`')
                && str_contains(strtolower($q), 'for update')),
            'DocumentNumberService::next() must read the counter row with a locking (FOR UPDATE) select.'
        );
    }

    // ---------------------------------------------------------------
    // The counter row is created lazily, at 1, on first use
    // ---------------------------------------------------------------

    public function test_counter_row_is_created_at_one_on_first_use(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);

        $this->assertSame(0, DB::table('document_counters')->where('company_id', $company->id)->count());

        DB::transaction(fn () => $this->service()->next('INV', 'INV'));

        $counter = DB::table('document_counters')->where('company_id', $company->id)->where('series', 'INV')->first();
        $this->assertNotNull($counter);
        $this->assertSame(2, (int) $counter->next_number);
    }
}
