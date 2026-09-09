<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('self_media_media_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('manual_publication_batch_id')->constrained('manual_publication_batches')->cascadeOnDelete();
            $table->string('media_key', 80);
            $table->unsignedSmallInteger('position');
            $table->string('source_type', 24);
            $table->string('source_url', 2000)->nullable();
            $table->string('alt_text', 500)->nullable();
            $table->boolean('used_as_cover')->default(false);
            $table->string('storage_disk', 40)->default('local');
            $table->string('storage_path', 1000);
            $table->char('sha256', 64);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('status', 24)->default('ready');
            $table->timestamps();

            $table->unique(['manual_publication_batch_id', 'media_key'], 'self_media_snapshot_batch_key_unique');
            $table->index(['manual_publication_batch_id', 'position'], 'self_media_snapshot_batch_position');
        });

        Schema::table('manual_publications', function (Blueprint $table): void {
            $table->string('document_schema_version', 64)->nullable()->after('body_html');
            $table->json('portable_document')->nullable()->after('document_schema_version');
            $table->json('render_fingerprint')->nullable()->after('portable_document');
            $table->string('content_type', 64)->nullable()->after('render_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('manual_publications', function (Blueprint $table): void {
            $table->dropColumn(['document_schema_version', 'portable_document', 'render_fingerprint', 'content_type']);
        });

        Schema::dropIfExists('self_media_media_snapshots');
    }
};
