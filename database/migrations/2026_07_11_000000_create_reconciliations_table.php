<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $t->date('statement_date');
            // Minor units. Signed (bigInteger, not unsigned) — an overdrawn
            // account's statement balance is a legitimate negative figure.
            $t->bigInteger('statement_ending_balance');
            // Carried from the PRIOR completed reconciliation's own
            // statement_ending_balance (0 for the account's first ever
            // reconciliation) — the chain ReconciliationService::complete()
            // recomputes fresh on every completion, never trusted from the
            // client (spec §7.9).
            $t->bigInteger('opening_reconciled_balance');
            // 'completed'|'reopened'. A reopened reconciliation's lines are
            // unlocked (its reconciliation_lines rows deleted) but the row
            // itself is kept for history — never deleted.
            $t->string('status', 10);
            $t->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('completed_at');
            $t->timestamps();

            $t->index('company_id');
            // The "most recent reconciliation for an account" query
            // (opening-balance chain + reopen newest-first) always filters by
            // bank_account_id then orders by statement_date/id — this index
            // covers both.
            $t->index(['bank_account_id', 'statement_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliations');
    }
};
