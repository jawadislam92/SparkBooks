<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opening_documents', function (Blueprint $t) {
            // Links a receivable opening_document to the is_opening Invoice
            // that represents it (Phase 3 Task 6, spec §6 P8b): the invoice
            // is the payable object — InvoicePaymentService::recordPayment
            // posts P2/P3 against it exactly like any other invoice — while
            // this row stays the audit/source record (contact/reference/
            // original_date). Nullable + set-null-on-delete: vendor
            // (payable) opening_documents never get one (Phase 4 bills are
            // the symmetric feature, not this task), and an invoice being
            // deleted must not take its source opening_document down with
            // it (in practice a posted is_opening invoice is never deleted —
            // only a draft can be, and these are created straight to Sent —
            // but nullOnDelete is the safe default rather than restricting).
            $t->foreignId('invoice_id')->nullable()->after('contact_id')->constrained('invoices')->nullOnDelete();
            $t->index(['company_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::table('opening_documents', function (Blueprint $t) {
            $t->dropForeign(['invoice_id']);
            $t->dropIndex(['company_id', 'invoice_id']);
            $t->dropColumn('invoice_id');
        });
    }
};
