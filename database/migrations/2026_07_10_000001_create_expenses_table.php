<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            // Plain unsignedBigInteger column here — the composite FK below
            // (on contact_id + company_id) is what actually constrains it, so
            // no ->constrained() chain on this line. Same reasoning as
            // invoices.contact_id (Phase 3 T1). Nullable: an expense's payee
            // is optional (e.g. a cash purchase with no vendor on file) —
            // unlike invoices/bills, which always name a contact. MySQL's
            // default MATCH SIMPLE semantics skip the composite FK check
            // entirely whenever contact_id is NULL, so NULL never needs a
            // matching contacts row.
            $t->unsignedBigInteger('contact_id')->nullable();
            // ExpenseStatus enum values: draft|recorded|void.
            $t->string('status', 16)->default('draft');
            $t->date('expense_date')->nullable();
            // Paid-from bank/cash account. Nullable at the schema level (a
            // draft expense may not have one chosen yet); required by the
            // service layer at record time (later task).
            $t->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->restrictOnDelete();
            // Minor units.
            $t->unsignedBigInteger('subtotal')->default(0);
            $t->unsignedBigInteger('tax_total')->default(0);
            $t->unsignedBigInteger('total')->default(0);
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('recorded_at')->nullable();
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
        Schema::dropIfExists('expenses');
    }
};
