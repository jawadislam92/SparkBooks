<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Phase 6 Task 5 (spec §17.5): the lightweight close-progress record
        // for the two manual-acknowledge steps (A/R+A/P aging review, Trial
        // Balance review) plus the "close completed" marker — one row per
        // company per month-end date. Every OTHER step (reconcile/drafts/
        // integrity/lock) reflects real system state computed fresh from
        // existing tables (reconciliations, invoices/bills/expenses,
        // integrity_runs, companies.books_locked_through) — nothing about
        // those is stored here.
        Schema::create('month_end_closes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->date('month_end');
            $t->timestamp('ar_ap_reviewed_at')->nullable();
            $t->timestamp('trial_balance_reviewed_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['company_id', 'month_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('month_end_closes');
    }
};
