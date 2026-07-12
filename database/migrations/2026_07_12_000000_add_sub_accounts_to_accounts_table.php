<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 Task 1 (sub-accounts): a PURE ADDITIVE migration — it must not move
 * or alter a single existing debit/credit/amount. Four new columns on
 * `accounts`:
 *
 * - `parent_id`: nullable self-FK, restrictOnDelete (never allow deleting an
 *   account that still has children — accounts aren't deleted anyway, see
 *   Account::booted()'s deleting guard, but the FK is the DB-level backstop).
 * - `is_postable`: EXPLICIT boolean, default true. Deliberately NOT derived
 *   from "has children" — a freshly-created leaf that later gains a child is
 *   flipped to false by AccountService::makeParent() in the same
 *   transaction that creates the child, never inferred at read time.
 * - `depth`: unsignedTinyInteger, default 0 — 0 for every top-level account.
 * - `path`: materialized ancestor path, e.g. `/12/152/` (this account's own
 *   id is the last segment). Indexed so `WHERE path LIKE '/12/%'` (the
 *   descendant-lookup every report rollup and IntegrityChecker rule uses)
 *   stays a prefix-index scan, not a full table scan.
 *
 * Backfill (same migration, one UPDATE, no PHP loop): every existing account
 * becomes a top-level postable leaf — parent_id stays NULL (the column
 * default), is_postable stays true (the column default), depth stays 0 (the
 * column default), and path is set to '/{id}/'. Zero data movement: no
 * journal_lines row is touched, no account's code/name/type/subtype changes.
 * Existing lines still point at these (now-explicitly-leaf) accounts and
 * remain valid postings.
 *
 * `path` is left nullable at the schema level rather than converted to NOT
 * NULL after backfill — this project has no doctrine/dbal dependency, so
 * Schema::table(...)->change() isn't available. Every row is populated by
 * the backfill UPDATE below, and every future writer (ChartOfAccountsSeeder,
 * AccountController::store, AccountService::createChild/makeParent) always
 * sets path explicitly — there is no code path that leaves it null in
 * practice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $t) {
            $t->foreignId('parent_id')->nullable()->after('company_id')
                ->constrained('accounts')->restrictOnDelete();
            $t->boolean('is_postable')->default(true)->after('is_archived');
            $t->unsignedTinyInteger('depth')->default(0)->after('is_postable');
            $t->string('path', 255)->nullable()->after('depth');
            $t->index('path');
        });

        // Single-statement backfill: every pre-existing account (across every
        // company) becomes a top-level postable leaf. parent_id/is_postable/
        // depth already default to NULL/true/0 for these pre-existing rows
        // (the column defaults above apply to rows that existed before the
        // column was added, exactly like every other ALTER TABLE ... ADD
        // COLUMN ... DEFAULT in this codebase) — only `path`, which
        // necessarily depends on each row's own id, needs an explicit value.
        DB::statement("UPDATE accounts SET path = CONCAT('/', id, '/')");
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('parent_id');
            $t->dropColumn(['is_postable', 'depth', 'path']);
        });
    }
};
