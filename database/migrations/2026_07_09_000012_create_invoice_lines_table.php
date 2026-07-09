<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $t) {
            $t->id();
            // Lines belong to their invoice's lifecycle: cascade-delete only
            // makes sense while the invoice is a draft (the InvoiceLine model
            // guard blocks deleting/editing lines once the invoice is
            // posted — see InvoiceLine::booted()).
            $t->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->string('description', 255);
            $t->decimal('quantity', 12, 4)->default(1);
            // Minor units, document currency.
            $t->unsignedBigInteger('unit_price')->default(0);
            // Accounts are archived, never deleted (Account::booted() throws
            // on delete) — restrictOnDelete is consistent even though the ON
            // DELETE branch can't actually fire in practice.
            $t->foreignId('income_account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->restrictOnDelete();
            // The tax rate COPIED at send time (spec §7.6) — tax_rates.rate
            // can change later without altering an already-sent invoice's tax.
            $t->decimal('tax_rate_snapshot', 7, 4)->nullable();
            // Minor units, document currency.
            $t->unsignedBigInteger('line_subtotal')->default(0);
            $t->unsignedBigInteger('line_tax')->default(0);
            $t->timestamps();

            $t->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
