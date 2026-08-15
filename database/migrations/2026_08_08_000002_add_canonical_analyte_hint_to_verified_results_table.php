<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive only. Carries forward the OCR-resolved analyte identity as a *hint* --
     * never a replacement for the verified label/value/unit, which remain untouched.
     * Populated by VerifyReport only when the verified label text still matches the
     * source extracted row's raw label (see VerifyReport::canonicalAnalyteHintFor());
     * cleared to NULL the moment a user edits the label, or for manually-added rows
     * that never had an OCR basis. KBS treats this as an optional, independently
     * re-validated candidate -- it never blindly trusts it.
     *
     * Existing rows get NULL, which is exactly the current (pre-hint) fallback-only
     * resolution behavior.
     */
    public function up(): void
    {
        Schema::table('verified_results', function (Blueprint $table) {
            $table->string('canonical_analyte_id_hint', 64)->nullable()->after('reference_range');
        });
    }

    public function down(): void
    {
        Schema::table('verified_results', function (Blueprint $table) {
            $table->dropColumn('canonical_analyte_id_hint');
        });
    }
};
