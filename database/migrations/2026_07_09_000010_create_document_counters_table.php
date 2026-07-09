<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_counters', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            // 'INV' today; later 'BILL'/'OB' etc — one row per (company, series).
            $t->string('series', 10);
            $t->unsignedBigInteger('next_number')->default(1);
            $t->timestamps();
            // One counter row per company+series — DocumentNumberService locks
            // this row FOR UPDATE and increments it to hand out gap-free,
            // duplicate-free numbers under concurrency (see the service).
            $t->unique(['company_id', 'series']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_counters');
    }
};
