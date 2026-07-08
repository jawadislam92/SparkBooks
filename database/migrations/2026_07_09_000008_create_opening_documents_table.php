<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('opening_balance_run_id')->constrained('opening_balance_runs')->restrictOnDelete();
            // Plain unsignedBigInteger column here — the composite FK below
            // (on contact_id + company_id) is what actually constrains it, so
            // no ->constrained() chain on this line.
            $t->unsignedBigInteger('contact_id');
            $t->string('kind', 12); // 'receivable'|'payable' — P8b/P8c
            $t->string('reference', 120)->nullable();
            $t->date('original_date');
            $t->date('due_date');
            $t->unsignedBigInteger('amount'); // minor units
            $t->string('status', 10)->default('open'); // 'open'|'void'
            $t->timestamps();
            $t->index('company_id');
            $t->index(['company_id', 'opening_balance_run_id']);

            // Composite FK on (contact_id, company_id) -> contacts(id, company_id)
            // — same tenant-safety reasoning as journal_lines.contact_id (see
            // 2026_07_09_000001_add_fk_to_journal_lines_contact_id.php). A plain
            // single-column FK to contacts.id could accept a contact belonging
            // to a different company; the composite key forces same-tenant.
            // Unlike journal_lines, contact_id is NOT NULL here — every opening
            // AR/AP document always names a real contact (spec §6 note: this is
            // what makes aging tie out in Phase 5).
            $t->foreign(['contact_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('contacts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_documents');
    }
};
