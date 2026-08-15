<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive only: preserves the existing raw_* columns exactly as-is. OCR already
     * resolves a candidate analyte identity (canonical_name/test_code/kbs_test_id) and a
     * normalized value/unit/reference for many rows, but that interpretation was
     * previously discarded after storage (buried, unread, inside raw_row_json). These
     * columns make it available downstream without ever replacing the raw record.
     *
     * Existing rows get NULL in every new column, which is exactly the "no OCR
     * interpretation available" state the KBS fallback resolver already handles.
     */
    public function up(): void
    {
        Schema::table('extracted_results', function (Blueprint $table) {
            $table->text('ocr_canonical_name')->nullable()->after('raw_reference');
            $table->string('ocr_test_code', 64)->nullable()->after('ocr_canonical_name');
            $table->string('ocr_kbs_test_id', 64)->nullable()->after('ocr_test_code');
            $table->text('ocr_normalized_value')->nullable()->after('ocr_kbs_test_id');
            $table->text('ocr_normalized_unit')->nullable()->after('ocr_normalized_value');
            $table->text('ocr_normalized_reference')->nullable()->after('ocr_normalized_unit');
            $table->index('ocr_kbs_test_id');
        });
    }

    public function down(): void
    {
        Schema::table('extracted_results', function (Blueprint $table) {
            $table->dropIndex(['ocr_kbs_test_id']);
            $table->dropColumn([
                'ocr_canonical_name',
                'ocr_test_code',
                'ocr_kbs_test_id',
                'ocr_normalized_value',
                'ocr_normalized_unit',
                'ocr_normalized_reference',
            ]);
        });
    }
};
