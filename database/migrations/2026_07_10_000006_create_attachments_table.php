<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            // attachable_id + attachable_type, indexed — polymorphic parent
            // (Expense or Bill for Phase 4; any future attachable model
            // could reuse this same table).
            $t->morphs('attachable');
            $t->string('disk_path');
            $t->string('original_name');
            $t->string('mime', 80);
            $t->unsignedBigInteger('size');
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
