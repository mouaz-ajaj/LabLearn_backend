<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verified_result_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('patient_age_years', 5, 2);
            $table->string('patient_sex', 16);
            $table->string('idempotency_key', 64);
            $table->json('excluded_source_result_ids')->nullable();
            $table->string('category_gate_status', 16);
            $table->string('category_gate_category', 32)->nullable();
            $table->json('category_gate_evidence')->nullable();
            $table->timestamp('confirmed_at');
            $table->timestamps();

            $table->unique(['report_id', 'version']);
            $table->unique(['report_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verified_result_sets');
    }
};
