<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_lines', function (Blueprint $t) {
            $t->id();
            // Mirrors expense_lines.expense_id — cascade-delete only makes
            // sense while the bill is a draft (BillLine::booted() guard
            // blocks deleting/editing lines once the bill has left draft
            // status).
            $t->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->string('description', 255);
            $t->foreignId('category_account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->restrictOnDelete();
            // The tax rate COPIED when the bill is received — tax_rates.rate
            // can change later without altering an already-received bill's tax.
            $t->decimal('tax_rate_snapshot', 7, 4)->nullable();
            // Minor units, net of tax. No quantity/unit_price columns —
            // mirrors expense_lines' simpler (no-quantity) shape.
            $t->unsignedBigInteger('amount')->default(0);
            $t->unsignedBigInteger('line_tax')->default(0);
            $t->timestamps();

            $t->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_lines');
    }
};
