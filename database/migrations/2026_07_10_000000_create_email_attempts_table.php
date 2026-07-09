<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $t->string('recipient', 255);
            // 'sent'|'failed' — see SendInvoiceEmail (spec §17.6).
            $t->string('status', 10);
            $t->text('error')->nullable();
            $t->timestamp('attempted_at');
            $t->timestamps();

            $t->index(['company_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_attempts');
    }
};
