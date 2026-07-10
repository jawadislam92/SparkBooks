<?php

namespace Tests\Unit;

use App\Services\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * ReportPeriod's fiscal-year math is pure and DB-free, so this extends
 * PHPUnit's own TestCase (not Tests\TestCase / RefreshDatabase) — no app
 * boot, no database, on purpose. It also never calls now()/today() itself;
 * every date comes in as an explicit $asOf/$since argument (see class docblock).
 */
class ReportPeriodTest extends TestCase
{
    // -----------------------------------------------------------------
    // Calendar-year fiscal year (fiscal_year_start_month = 1)
    // -----------------------------------------------------------------

    public function test_calendar_year_fiscal_year_start_is_january_first(): void
    {
        $period = new ReportPeriod(1);

        $start = $period->currentFiscalYearStart(CarbonImmutable::parse('2026-07-11'));

        $this->assertSame('2026-01-01', $start->toDateString());
    }

    public function test_calendar_year_fiscal_year_range_is_jan_one_to_dec_thirty_one(): void
    {
        $period = new ReportPeriod(1);

        $range = $period->currentFiscalYearRange(CarbonImmutable::parse('2026-07-11'));

        $this->assertSame('2026-01-01', $range['start']->toDateString());
        $this->assertSame('2026-12-31', $range['end']->toDateString());
    }

    // -----------------------------------------------------------------
    // Non-January fiscal year (fiscal_year_start_month = 7 -> Jul 1..Jun 30)
    // -----------------------------------------------------------------

    public function test_non_january_fiscal_year_asof_before_boundary_is_in_prior_calendar_years_fy(): void
    {
        $period = new ReportPeriod(7);

        $start = $period->currentFiscalYearStart(CarbonImmutable::parse('2026-03-15'));

        $this->assertSame('2025-07-01', $start->toDateString());
    }

    public function test_non_january_fiscal_year_range_spans_jul_to_jun(): void
    {
        $period = new ReportPeriod(7);

        $range = $period->currentFiscalYearRange(CarbonImmutable::parse('2026-03-15'));

        $this->assertSame('2025-07-01', $range['start']->toDateString());
        $this->assertSame('2026-06-30', $range['end']->toDateString());
    }

    // -----------------------------------------------------------------
    // Boundary correctness — both sides of the fiscal year-start date
    // -----------------------------------------------------------------

    public function test_current_fiscal_year_start_on_the_boundary_date_itself_starts_the_new_fy(): void
    {
        $period = new ReportPeriod(7);

        $start = $period->currentFiscalYearStart(CarbonImmutable::parse('2026-07-01'));

        $this->assertSame('2026-07-01', $start->toDateString());
    }

    public function test_current_fiscal_year_start_the_day_before_the_boundary_is_still_the_prior_fy(): void
    {
        $period = new ReportPeriod(7);

        $start = $period->currentFiscalYearStart(CarbonImmutable::parse('2026-06-30'));

        $this->assertSame('2025-07-01', $start->toDateString());
    }

    public function test_calendar_year_boundary_on_jan_first_itself(): void
    {
        $period = new ReportPeriod(1);

        $start = $period->currentFiscalYearStart(CarbonImmutable::parse('2026-01-01'));

        $this->assertSame('2026-01-01', $start->toDateString());
    }

    public function test_calendar_year_boundary_on_dec_thirty_first_is_the_prior_year(): void
    {
        $period = new ReportPeriod(1);

        $start = $period->currentFiscalYearStart(CarbonImmutable::parse('2025-12-31'));

        $this->assertSame('2025-01-01', $start->toDateString());
    }

    // -----------------------------------------------------------------
    // completedFiscalYears
    // -----------------------------------------------------------------

    public function test_completed_fiscal_years_calendar_year_returns_each_prior_year_since_the_bound(): void
    {
        $period = new ReportPeriod(1);

        $years = $period->completedFiscalYears(
            CarbonImmutable::parse('2026-07-11'),
            CarbonImmutable::parse('2023-01-01'),
        );

        $this->assertCount(3, $years);
        $this->assertSame(['2023-01-01', '2023-12-31'], [$years[0]['start']->toDateString(), $years[0]['end']->toDateString()]);
        $this->assertSame(['2024-01-01', '2024-12-31'], [$years[1]['start']->toDateString(), $years[1]['end']->toDateString()]);
        $this->assertSame(['2025-01-01', '2025-12-31'], [$years[2]['start']->toDateString(), $years[2]['end']->toDateString()]);
    }

    public function test_completed_fiscal_years_non_january_returns_each_prior_fy_since_the_bound(): void
    {
        $period = new ReportPeriod(7);

        // Current FY (asOf 2026-03-15) is 2025-07-01..2026-06-30, so it must
        // NOT appear in the completed list.
        $years = $period->completedFiscalYears(
            CarbonImmutable::parse('2026-03-15'),
            CarbonImmutable::parse('2023-07-01'),
        );

        $this->assertCount(2, $years);
        $this->assertSame(['2023-07-01', '2024-06-30'], [$years[0]['start']->toDateString(), $years[0]['end']->toDateString()]);
        $this->assertSame(['2024-07-01', '2025-06-30'], [$years[1]['start']->toDateString(), $years[1]['end']->toDateString()]);
    }

    public function test_completed_fiscal_years_is_empty_when_since_falls_within_the_current_fy(): void
    {
        $period = new ReportPeriod(1);

        $years = $period->completedFiscalYears(
            CarbonImmutable::parse('2026-07-11'),
            CarbonImmutable::parse('2026-02-01'),
        );

        $this->assertSame([], $years);
    }

    public function test_completed_fiscal_years_is_empty_when_since_equals_asof(): void
    {
        $period = new ReportPeriod(7);

        $years = $period->completedFiscalYears(
            CarbonImmutable::parse('2026-03-15'),
            CarbonImmutable::parse('2026-03-15'),
        );

        $this->assertSame([], $years);
    }

    // -----------------------------------------------------------------
    // Guard
    // -----------------------------------------------------------------

    public function test_fiscal_year_start_month_below_range_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReportPeriod(0);
    }

    public function test_fiscal_year_start_month_above_range_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReportPeriod(13);
    }
}
