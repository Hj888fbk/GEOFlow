<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('content_source_files', function (Blueprint $table) {
            $table->id();
            $table->string('source_root_key', 80);
            $table->text('relative_path');
            $table->char('path_hash', 64);
            $table->char('sha256', 64)->index();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('mime_type', 160)->nullable();
            $table->string('source_version', 80)->nullable();
            $table->date('source_date')->nullable();
            $table->string('evidence_status', 60)->index();
            $table->string('public_permission', 40)->default('internal_only')->index();
            $table->boolean('is_approved')->default(false)->index();
            $table->json('metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['source_root_key', 'path_hash'], 'content_source_files_root_path_unique');
            $table->index(['is_approved', 'evidence_status'], 'content_source_files_approval_evidence');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_source_files');
    }
};
