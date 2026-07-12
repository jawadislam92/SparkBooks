<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Backfill for Phase 7 Task 3 (visible entry numbers): assigns
 * `journal_entries.entry_number` to every UN-numbered row, per company, in
 * deterministic posted order (entry_date, then id — the same tie-break
 * GeneralLedgerReport/AccountController already sort by), placing each ABOVE
 * that company's current maximum entry_number (max, or 0 if none). Driven by
 * a single UPDATE...JOIN window function (no PHP loop), mirroring the "one
 * UPDATE" style of the accounts.path backfill.
 *
 * IDEMPOTENT / re-run-safe (review fix). It only touches rows where
 * `entry_number IS NULL`, so:
 *  - a plain re-run finds nothing to number → no-op;
 *  - a re-run after new out-of-order (backdated) UN-numbered rows have landed
 *    — even after real posts already consumed the counter — numbers only
 *    those new rows, above the existing max, so it can never collide on the
 *    `unique(company_id, entry_number)` index or renumber already-numbered rows.
 *
 * Then advances each company's `document_counters` 'JE' row to
 * max(entry_number) + 1 so DocumentNumberService's next allocation
 * (LedgerService::post → nextNumber('JE')) continues the sequence with no gap
 * and no collision — using GREATEST() so it never moves a counter backwards
 * that post-time numbering has already pushed ahead. A company with zero
 * entries is deliberately left unseeded — allocate()'s own insertOrIgnore
 * lazily creates its counter at 1 on the first real post.
 *
 * A standalone service (not inlined in the migration) so EntryNumberTest can
 * exercise it directly against synthetic out-of-order rows.
 */
class EntryNumberBackfiller
{
    public function backfill(): void
    {
        // Number only still-unnumbered rows, above each company's current max.
        DB::statement(<<<'SQL'
            UPDATE journal_entries je
            JOIN (
                SELECT unnumbered.id,
                       COALESCE(mx.max_num, 0) + ROW_NUMBER() OVER (
                           PARTITION BY unnumbered.company_id
                           ORDER BY unnumbered.entry_date, unnumbered.id
                       ) AS new_number
                FROM journal_entries unnumbered
                LEFT JOIN (
                    SELECT company_id, MAX(entry_number) AS max_num
                    FROM journal_entries
                    WHERE entry_number IS NOT NULL
                    GROUP BY company_id
                ) mx ON mx.company_id = unnumbered.company_id
                WHERE unnumbered.entry_number IS NULL
            ) ranked ON ranked.id = je.id
            SET je.entry_number = ranked.new_number
        SQL);

        // Create a JE counter for companies that don't have one yet, at max+1.
        DB::statement(<<<'SQL'
            INSERT INTO document_counters (company_id, series, next_number, created_at, updated_at)
            SELECT je.company_id, 'JE', MAX(je.entry_number) + 1, NOW(), NOW()
            FROM journal_entries je
            LEFT JOIN document_counters dc
                ON dc.company_id = je.company_id AND dc.series = 'JE'
            WHERE dc.id IS NULL AND je.entry_number IS NOT NULL
            GROUP BY je.company_id
        SQL);

        // Advance existing counters so they're never behind the max entry_number
        // (GREATEST keeps a counter that post-time numbering already pushed ahead).
        DB::statement(<<<'SQL'
            UPDATE document_counters dc
            JOIN (
                SELECT company_id, MAX(entry_number) + 1 AS min_next
                FROM journal_entries
                WHERE entry_number IS NOT NULL
                GROUP BY company_id
            ) m ON m.company_id = dc.company_id
            SET dc.next_number = GREATEST(dc.next_number, m.min_next)
            WHERE dc.series = 'JE'
        SQL);
    }
}
