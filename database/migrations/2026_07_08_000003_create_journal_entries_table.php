<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->date('entry_date');
            $t->string('memo', 500);
            $t->string('source_type', 40);
            $t->unsignedBigInteger('source_id');
            $t->string('entry_role', 10)->default('primary');
            $t->foreignId('reverses_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('posted_at')->useCurrent();
            // §17.2 backstop: one primary + one reversal max per source
            $t->unique(['company_id', 'source_type', 'source_id', 'entry_role'], 'journal_entries_source_unique');
            $t->index(['company_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
