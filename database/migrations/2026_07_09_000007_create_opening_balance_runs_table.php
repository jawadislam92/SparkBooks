<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_balance_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->date('books_start_date');
            // 'active'|'voided' — spec §7.1: re-running the setup wizard voids
            // the prior run (reversal + status flip) rather than editing it in
            // place, so at most one run per company is ever 'active'.
            $t->string('status', 10)->default('active');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balance_runs');
    }
};
