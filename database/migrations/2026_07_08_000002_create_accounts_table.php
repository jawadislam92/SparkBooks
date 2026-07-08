<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->string('code', 10);
            $t->string('name', 120);
            $t->string('type', 12);        // AccountType enum values
            $t->string('subtype', 24);     // AccountSubtype enum values
            $t->boolean('is_system')->default(false);
            $t->boolean('is_archived')->default(false);
            $t->string('description', 500)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
