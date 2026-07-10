<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_lines', function (Blueprint $t) {
            $t->id();
            // Lines belong to their expense's lifecycle: cascade-delete only
            // makes sense while the expense is a draft (the ExpenseLine model
            // guard blocks deleting/editing lines once the expense has left
            // draft status — see ExpenseLine::booted()).
            $t->foreignId('expense_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->string('description', 255);
            // Accounts are archived, never deleted (Account::booted() throws
            // on delete) — restrictOnDelete is consistent even though the ON
            // DELETE branch can't actually fire in practice.
            $t->foreignId('category_account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->restrictOnDelete();
            // The tax rate COPIED at record time (mirrors invoice_lines'
            // tax_rate_snapshot) — tax_rates.rate can change later without
            // altering an already-recorded expense's tax.
            $t->decimal('tax_rate_snapshot', 7, 4)->nullable();
            // Minor units, net of tax. No quantity/unit_price columns —
            // expense lines are simpler than invoice lines by design (a
            // single net amount per line), per the Phase 4 brief.
            $t->unsignedBigInteger('amount')->default(0);
            $t->unsignedBigInteger('line_tax')->default(0);
            $t->timestamps();

            $t->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_lines');
    }
};
