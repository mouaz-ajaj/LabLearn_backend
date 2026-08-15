<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('storage_disk', 64);
            $table->string('storage_path');
            $table->string('mime_type', 128);
            $table->string('extension', 16);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_files');
    }
};
