<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * One-shot backfill for Phase 7 Task 3 (visible entry numbers): assigns
 * `journal_entries.entry_number` to every pre-existing row, per company, in
 * deterministic posted order (entry_date, then id — the same tie-break
 * GeneralLedgerReport/AccountController already sort by) — 1, 2, 3, …,
 * gap-free. Implemented as a single UPDATE...JOIN driven by a window
 * function (no PHP loop), mirroring the "one UPDATE" style of the
 * accounts.path backfill in add_sub_accounts_to_accounts_table.
 *
 * Then seeds each such company's `document_counters` 'JE' series row at
 * COUNT(*) + 1, so DocumentNumberService's very next allocation for that
 * company (LedgerService::post, via nextNumber('JE')) continues the
 * sequence with no gap and no collision. A company with zero pre-existing
 * entries is deliberately left unseeded here — DocumentNumberService::
 * allocate()'s own insertOrIgnore lazily creates that company's counter at
 * 1 on its first real post, exactly like every other series.
 *
 * Extracted out of the migration itself (rather than inlined DB::statement
 * calls in up()) so EntryNumberTest can exercise this exact backfill logic
 * directly — inserting synthetic out-of-order rows and asserting the
 * resulting numbering — without re-running a schema-altering migration
 * mid-test.
 */
class EntryNumberBackfiller
{
    public function backfill(): void
    {
        DB::statement(<<<'SQL'
            UPDATE journal_entries je
            JOIN (
                SELECT id, ROW_NUMBER() OVER (
                    PARTITION BY company_id ORDER BY entry_date, id
                ) AS rn
                FROM journal_entries
            ) ranked ON ranked.id = je.id
            SET je.entry_number = ranked.rn
        SQL);

        DB::statement(<<<'SQL'
            INSERT INTO document_counters (company_id, series, next_number, created_at, updated_at)
            SELECT je.company_id, 'JE', COUNT(*) + 1, NOW(), NOW()
            FROM journal_entries je
            LEFT JOIN document_counters dc
                ON dc.company_id = je.company_id AND dc.series = 'JE'
            WHERE dc.id IS NULL
            GROUP BY je.company_id
        SQL);
    }
}
