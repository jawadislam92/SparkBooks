<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->string('name', 120);
            $t->string('type', 4); // 'bank'|'cash'
            $t->char('currency_code', 3);
            // The auto-created linked CoA account (BankAccountService::create).
            // restrictOnDelete: accounts are never deleted anyway (Account
            // model guard), but this keeps the FK's own semantics honest.
            $t->foreignId('ledger_account_id')->constrained('accounts')->restrictOnDelete();
            $t->boolean('is_archived')->default(false);
            $t->timestamps();
            $t->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
