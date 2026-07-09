<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $t) {
            // Composite FK on (contact_id, company_id) referencing contacts'
            // (id, company_id) — deliberately NOT a plain single-column FK on
            // contact_id alone. A single-column FK only guarantees the id
            // exists somewhere in `contacts`; it would happily accept a
            // contact_id that belongs to a completely different company. The
            // composite key forces the referenced contact to belong to the
            // SAME company as the line, giving tenant isolation a real DB-level
            // guarantee instead of relying on application code alone. NULL
            // contact_id (no contact on this line) is exempt from the check per
            // SQL MATCH SIMPLE semantics — any NULL column in a composite FK
            // skips the constraint for that row.
            // restrictOnDelete: contacts are archived, never deleted (see
            // Contact — there is intentionally no delete path), so this should
            // never actually fire in practice; restrict is the safe default.
            $t->foreign(['contact_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('contacts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $t) {
            $t->dropForeign(['contact_id', 'company_id']);
        });
    }
};
