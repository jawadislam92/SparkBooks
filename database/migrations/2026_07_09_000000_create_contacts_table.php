<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->string('type', 10); // ContactType enum values: customer|vendor|both
            $t->string('name', 120);
            $t->string('email')->nullable();
            $t->string('phone', 40)->nullable();
            $t->text('billing_address')->nullable();
            $t->string('tax_number', 60)->nullable();
            $t->text('notes')->nullable();
            $t->boolean('is_archived')->default(false);
            $t->timestamps();
            $t->index(['company_id', 'type']);
            // Composite unique (not just a unique id) so journal_lines can add a
            // composite FK on (contact_id, company_id) — see the next migration.
            // A plain FK to contacts.id alone could only reject a contact_id that
            // doesn't exist at all; it couldn't reject one that exists but
            // belongs to a different company, which is the tenant-isolation
            // guarantee Phase 2 needs.
            $t->unique(['id', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
