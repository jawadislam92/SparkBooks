<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('other_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $t->foreignId('category_account_id')->constrained('accounts')->restrictOnDelete();
            $t->date('receipt_date');
            $t->unsignedBigInteger('amount'); // minor units
            $t->string('payer', 120)->nullable();
            $t->string('memo', 500)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('other_receipts');
    }
};
