<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            // Plain unsignedBigInteger column here — the composite FK below
            // (on contact_id + company_id) is what actually constrains it, so
            // no ->constrained() chain on this line. Same reasoning as
            // opening_documents.contact_id (Phase 2 T8): a plain single-column
            // FK to contacts.id could accept a contact belonging to a
            // different company; the composite key forces same-tenant.
            $t->unsignedBigInteger('contact_id');
            // Null until the invoice is sent — DocumentNumberService assigns
            // it at send time, not at draft creation (draft invoices are
            // freely re-editable/deletable and shouldn't burn a number).
            $t->string('number', 20)->nullable();
            // InvoiceStatus enum values: draft|sent|partially_paid|paid|void.
            $t->string('status', 16)->default('draft');
            $t->date('issue_date')->nullable();
            $t->date('due_date')->nullable();
            $t->char('currency_code', 3);
            // Document-currency -> base-currency rate at issue (FX-lite,
            // spec §17.1 Path 2). 1.00000000 for same-currency invoices.
            $t->decimal('fx_rate', 16, 8)->default(1);
            // Minor units, in DOCUMENT currency.
            $t->unsignedBigInteger('subtotal')->default(0);
            $t->unsignedBigInteger('tax_total')->default(0);
            $t->unsignedBigInteger('total')->default(0);
            $t->unsignedBigInteger('amount_paid')->default(0);
            $t->unsignedBigInteger('balance')->default(0);
            // Minor units, in BASE currency (mirrors of the three totals
            // above, converted at fx_rate) — what the ledger actually posts.
            $t->unsignedBigInteger('base_subtotal')->default(0);
            $t->unsignedBigInteger('base_tax_total')->default(0);
            $t->unsignedBigInteger('base_total')->default(0);
            $t->boolean('is_opening')->default(false);
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('voided_at')->nullable();
            // 'not_sent'|'sending'|'sent'|'failed' etc — Task 10 (email) owns
            // the actual value set; this task only reserves the column.
            $t->string('email_status', 12)->default('not_sent');
            $t->timestamps();

            $t->index(['company_id', 'status']);
            $t->index(['company_id', 'contact_id']);
            // MySQL/InnoDB treats every NULL in a unique index as distinct, so
            // any number of draft invoices (number IS NULL) coexist per
            // company without collision; only a real, non-null number must be
            // unique per company. Verified by a test (multiple drafts insert
            // fine; two 'INV-0001' rows for one company do not).
            $t->unique(['company_id', 'number']);

            $t->foreign(['contact_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('contacts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
