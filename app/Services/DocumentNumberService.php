<?php

namespace App\Services;

use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;
use LogicException;

class DocumentNumberService
{
    public function __construct(private CompanyContext $context) {}

    /**
     * Hands out the next gap-free, duplicate-free number in a per-company,
     * per-series sequence, e.g. next('INV', 'INV') => 'INV-0001', 'INV-0002', …
     * Numbers past 9999 just get longer (no fixed cap).
     *
     * MUST run inside the caller's transaction — the document row that
     * consumes this number and the counter increment must commit or roll
     * back together (same reasoning as LedgerService::post). Concurrency
     * safety mirrors BankAccountService::nextAccountCode(): the counter row
     * is read with `lockForUpdate()`, which — because this call is inside
     * the caller's transaction — serializes concurrent same-company/series
     * callers (the second blocks until the first commits, then reads the
     * freshly incremented value).
     *
     * The counter row is created at next_number=1 on first use via
     * `insertOrIgnore` (unique(company_id, series) lets at most one
     * concurrent first-caller's insert actually land; everyone else's is
     * silently skipped), and only THEN locked and read — so the very first
     * call for a series is just as concurrency-safe as the 1000th.
     */
    public function next(string $series, string $prefix): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('DocumentNumberService::next must run inside the caller\'s transaction.');
        }

        return sprintf('%s-%04d', $prefix, $this->allocate($series));
    }

    /**
     * Phase 7 Task 3 (visible entry numbers): the same gap-free, per-company
     * counter machinery as next(), but returns the raw integer instead of a
     * formatted "PREFIX-0001" string — used by LedgerService::post to fill
     * journal_entries.entry_number (a stored integer column, not a string),
     * with the display form derived separately by
     * JournalEntry::displayNumber(). Same transaction requirement, same
     * FOR-UPDATE row-lock concurrency guarantee as next() — see its docblock.
     */
    public function nextNumber(string $series): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('DocumentNumberService::nextNumber must run inside the caller\'s transaction.');
        }

        return $this->allocate($series);
    }

    private function allocate(string $series): int
    {
        $companyId = $this->context->id();

        DB::table('document_counters')->insertOrIgnore([
            'company_id' => $companyId,
            'series' => $series,
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $counter = DB::table('document_counters')
            ->where('company_id', $companyId)
            ->where('series', $series)
            ->lockForUpdate()
            ->first();

        $number = (int) $counter->next_number;

        DB::table('document_counters')
            ->where('id', $counter->id)
            ->update([
                'next_number' => $number + 1,
                'updated_at' => now(),
            ]);

        return $number;
    }
}
