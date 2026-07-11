<?php

use App\Services\EntryNumberBackfiller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 Task 3 (visible entry numbers): a human-readable, gap-free,
 * per-company sequential number on every journal entry — DISTINCT from the
 * DB `id` — e.g. entry_number=142 displays as "JE-000142"
 * (JournalEntry::displayNumber()). Purely additive: no existing debit/
 * credit/amount/entry_date is touched.
 *
 * `entry_number` is left NULLABLE at the schema level (same reasoning as
 * `accounts.path` in add_sub_accounts_to_accounts_table: this project has no
 * doctrine/dbal dependency, so Schema::table(...)->change() to NOT NULL
 * isn't available). Every existing row is populated by the backfill below,
 * and every future writer (LedgerService::post, via DocumentNumberService's
 * new 'JE' counter series) always sets it — there is no code path that
 * leaves it null in practice.
 *
 * unique(company_id, entry_number) is the DB-level backstop that a
 * concurrent double-allocation (a LedgerService::post bug, not the
 * FOR-UPDATE-guarded DocumentNumberService itself) would violate loudly
 * instead of silently duplicating a number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $t) {
            $t->unsignedBigInteger('entry_number')->nullable()->after('entry_role');
            $t->unique(['company_id', 'entry_number']);
        });

        // Backfill ordering (entry_date, then id) + seeding the 'JE'
        // document_counters row per company — see EntryNumberBackfiller's
        // docblock for why this lives in a standalone service rather than
        // inline DB::statement calls here.
        app(EntryNumberBackfiller::class)->backfill();
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $t) {
            $t->dropUnique(['company_id', 'entry_number']);
            $t->dropColumn('entry_number');
        });

        DB::table('document_counters')->where('series', 'JE')->delete();
    }
};
