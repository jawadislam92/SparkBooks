<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->string('name', 120);
            $t->text('description')->nullable();
            // Minor units (e.g. paisa/cents) — never a float. Client converts a
            // typed decimal via parseMoneyInput() before submitting.
            $t->unsignedBigInteger('unit_price')->default(0);
            // Both link to reference data that is archived, never deleted (Account
            // throws on delete; TaxRate follows the same convention), so
            // restrictOnDelete is consistent even though neither FK's ON DELETE
            // branch can actually be exercised in practice.
            $t->foreignId('default_income_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $t->foreignId('default_tax_rate_id')->nullable()->constrained('tax_rates')->restrictOnDelete();
            $t->boolean('is_archived')->default(false);
            $t->timestamps();
            $t->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
