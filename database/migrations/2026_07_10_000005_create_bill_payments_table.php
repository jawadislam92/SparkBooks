<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('bill_id')->constrained()->restrictOnDelete();
            $t->date('payment_date');
            // Minor units. No base_amount/fx_rate — bills are base currency
            // only (Phase 4 brief; unlike Phase 3's invoice_payments).
            $t->unsignedBigInteger('amount');
            $t->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            // 'posted'|'void'.
            $t->string('status', 10)->default('posted');
            $t->string('memo', 500)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index('company_id');
            $t->index(['company_id', 'bill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_payments');
    }
};
