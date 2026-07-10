<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            // Plain unsignedBigInteger column here — the composite FK below
            // (on contact_id + company_id) is what actually constrains it.
            // Unlike expenses.contact_id, this is NOT NULL — a bill always
            // names a vendor (same reasoning as invoices.contact_id, Phase
            // 3 T1, and opening_documents.contact_id, Phase 2 T8).
            $t->unsignedBigInteger('contact_id');
            // The VENDOR's own bill/invoice number — bills carry no internal
            // gap-free numbering of their own (spec decision, Phase 4 brief).
            $t->string('reference', 120)->nullable();
            // BillStatus enum values: draft|received|partially_paid|paid|void.
            $t->string('status', 16)->default('draft');
            $t->date('bill_date')->nullable();
            $t->date('due_date')->nullable();
            // Minor units. No fx_rate/base_* columns — bills are base
            // currency only (Phase 4 brief; unlike Phase 3's invoices).
            $t->unsignedBigInteger('subtotal')->default(0);
            $t->unsignedBigInteger('tax_total')->default(0);
            $t->unsignedBigInteger('total')->default(0);
            $t->unsignedBigInteger('amount_paid')->default(0);
            $t->unsignedBigInteger('balance')->default(0);
            $t->boolean('is_opening')->default(false);
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Mirrors invoices.sent_at — set when the bill leaves draft
            // status (i.e. is "received").
            $t->timestamp('received_at')->nullable();
            $t->timestamp('voided_at')->nullable();
            $t->timestamps();

            $t->index(['company_id', 'status']);
            $t->index(['company_id', 'contact_id']);

            $t->foreign(['contact_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('contacts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};
