<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opening_documents', function (Blueprint $t) {
            // Links a payable opening_document to the is_opening Bill that
            // represents it (Phase 4 Task 7 — the exact AP-side mirror of
            // invoice_id, added 2026_07_09_000014): the bill is the payable
            // object — BillPaymentService::record posts P7 against it exactly
            // like any other bill — while this row stays the audit/source
            // record (contact/reference/original_date). Nullable + set-null-
            // on-delete: receivable (customer) opening_documents never get
            // one (those get invoice_id instead), and a bill being deleted
            // must not take its source opening_document down with it (in
            // practice a posted is_opening bill is never deleted — only a
            // draft can be, and these are created straight to Received — but
            // nullOnDelete is the safe default rather than restricting).
            $t->foreignId('bill_id')->nullable()->after('invoice_id')->constrained('bills')->nullOnDelete();
            $t->index(['company_id', 'bill_id']);
        });
    }

    public function down(): void
    {
        Schema::table('opening_documents', function (Blueprint $t) {
            $t->dropForeign(['bill_id']);
            $t->dropIndex(['company_id', 'bill_id']);
            $t->dropColumn('bill_id');
        });
    }
};
