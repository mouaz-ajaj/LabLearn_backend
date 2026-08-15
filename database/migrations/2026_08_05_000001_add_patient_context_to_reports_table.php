<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->decimal('patient_age_years', 5, 2)->nullable()->after('report_date');
            $table->string('patient_sex', 16)->nullable()->after('patient_age_years');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn(['patient_age_years', 'patient_sex']);
        });
    }
};
