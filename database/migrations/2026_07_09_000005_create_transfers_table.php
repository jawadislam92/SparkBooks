<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('from_bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $t->foreignId('to_bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $t->date('transfer_date');
            $t->unsignedBigInteger('amount'); // minor units
            $t->string('memo', 500)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};
