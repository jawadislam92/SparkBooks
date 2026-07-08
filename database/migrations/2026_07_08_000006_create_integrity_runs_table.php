<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrity_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->timestamp('ran_at');
            $t->json('findings')->nullable();
            $t->boolean('clean');
            $t->index(['company_id', 'ran_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrity_runs');
    }
};
