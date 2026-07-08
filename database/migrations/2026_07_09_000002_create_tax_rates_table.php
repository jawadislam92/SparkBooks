<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->string('name', 60);
            // Percent, e.g. 18.0000. Range 0-100 is enforced at the request
            // validation layer (Store/UpdateTaxRateRequest), not here.
            $t->decimal('rate', 7, 4);
            $t->boolean('is_archived')->default(false);
            $t->timestamps();
            $t->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
