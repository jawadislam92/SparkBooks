<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Fiscal-year math for one company, parameterised by
 * `companies.fiscal_year_start_month` (1 = calendar year; e.g. 7 = a fiscal
 * year running Jul 1..Jun 30). Every method takes the "as of" (and, where
 * relevant, "since") date as an explicit argument — this class never calls
 * now()/today() itself, so it stays pure and trivially testable.
 *
 * "Current fiscal year" means: the FY whose [start, start+1yr) window
 * contains $asOf. A "completed" fiscal year is one that ends strictly
 * before that window starts.
 */
final readonly class ReportPeriod
{
    public function __construct(public int $fiscalYearStartMonth = 1)
    {
        if ($this->fiscalYearStartMonth < 1 || $this->fiscalYearStartMonth > 12) {
            throw new InvalidArgumentException('fiscalYearStartMonth must be between 1 and 12.');
        }
    }

    /** The first day of the fiscal year that contains $asOf. */
    public function currentFiscalYearStart(CarbonInterface $asOf): CarbonImmutable
    {
        $asOf = CarbonImmutable::instance($asOf)->startOfDay();
        $start = CarbonImmutable::create($asOf->year, $this->fiscalYearStartMonth, 1)->startOfDay();

        // $asOf falls before this calendar year's fiscal start (e.g. FY starts
        // in July and $asOf is in March) -> it belongs to the FY that began
        // the PRIOR calendar year.
        return $asOf->lt($start) ? $start->subYear() : $start;
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function currentFiscalYearRange(CarbonInterface $asOf): array
    {
        $start = $this->currentFiscalYearStart($asOf);

        return ['start' => $start, 'end' => $start->addYear()->subDay()];
    }

    /**
     * Every fiscal year that ended strictly before the FY containing $asOf,
     * back to (and including) the FY containing $since.
     *
     * $since is required on purpose: there is no sensible unbounded default
     * for "how far back". Callers building a retained-earnings rollup (e.g.
     * the Balance Sheet) should pass the company's `books_start_date`.
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    public function completedFiscalYears(CarbonInterface $asOf, CarbonInterface $since): array
    {
        $currentStart = $this->currentFiscalYearStart($asOf);
        $cursor = $this->currentFiscalYearStart($since);

        $years = [];
        while ($cursor->lt($currentStart)) {
            $years[] = ['start' => $cursor, 'end' => $cursor->addYear()->subDay()];
            $cursor = $cursor->addYear();
        }

        return $years;
    }
}
