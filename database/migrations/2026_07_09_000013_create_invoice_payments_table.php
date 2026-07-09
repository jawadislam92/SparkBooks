<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $t->date('payment_date');
            // Minor units, document currency.
            $t->unsignedBigInteger('amount');
            // Minor units, base currency — the actual amount that landed in
            // the bank account, at the payment-date FX rate (may differ from
            // amount * invoice.fx_rate — that's the FX gain/loss, spec §17.1).
            $t->unsignedBigInteger('base_amount');
            $t->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            // Payment-date rate, nullable (only meaningful for a foreign-
            // currency invoice; same-currency payments leave this null).
            $t->decimal('fx_rate', 16, 8)->nullable();
            // 'posted'|'void'.
            $t->string('status', 10)->default('posted');
            $t->string('memo', 500)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index('company_id');
            $t->index(['company_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
    }
};
