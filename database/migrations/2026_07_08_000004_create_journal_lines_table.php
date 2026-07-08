<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('account_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('debit')->default(0);
            $t->unsignedBigInteger('credit')->default(0);
            $t->string('memo', 500)->nullable();
            $t->foreignId('contact_id')->nullable(); // FK added in Phase 2 when contacts exist
            $t->index(['company_id', 'account_id']);
        });
        // §17.3 CHECK: exactly one side positive
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT chk_one_sided CHECK ((debit = 0 AND credit > 0) OR (debit > 0 AND credit = 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
    }
};
