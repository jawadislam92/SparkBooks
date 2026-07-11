<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('reconciliation_id')->constrained()->cascadeOnDelete();
            // journal_lines are immutable and never deleted (ImmutableLedger)
            // — restrictOnDelete matches every other FK into the ledger
            // tables in this app (see invoice_payments' bank_account_id, for
            // instance) even though the delete path is unreachable in
            // practice.
            $t->foreignId('journal_line_id')->constrained('journal_lines')->restrictOnDelete();
            // The line's signed effect (debit − credit) on the bank account
            // AT TICK TIME — a historical record, not re-derived on read.
            $t->bigInteger('amount');
            $t->timestamps();

            $t->index('company_id');
            // THE reconciled marker (spec §7.9 / task brief): a journal line
            // can be reconciled by at most one LIVE reconciliation at a time.
            // Reopening a reconciliation deletes its rows here, which is what
            // makes a line reconcilable again — there is no separate
            // "reconciled" flag anywhere else (journal_lines stays immutable).
            $t->unique(['company_id', 'journal_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_lines');
    }
};
