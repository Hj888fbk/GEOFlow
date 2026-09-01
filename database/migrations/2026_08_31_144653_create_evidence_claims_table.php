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
        Schema::create('evidence_claims', function (Blueprint $table) {
            $table->id();
            $table->uuid('claim_id')->unique();
            $table->foreignId('content_task_id')->nullable()->constrained('content_tasks')->nullOnDelete();
            $table->foreignId('content_source_file_id')->nullable()->constrained('content_source_files')->nullOnDelete();
            $table->string('source_id', 160)->nullable()->index();
            $table->string('claim_type', 60)->index();
            $table->string('subject', 255);
            $table->string('predicate', 160);
            $table->text('claim_value');
            $table->string('unit', 40)->nullable();
            $table->text('scope')->nullable();
            $table->string('evidence_status', 60)->index();
            $table->string('public_permission', 40)->default('internal_only')->index();
            $table->json('structured_payload')->nullable();
            $table->string('official_lookup_url', 1000)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable()->index();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('conflict_notes')->nullable();
            $table->timestamps();

            $table->index(['claim_type', 'evidence_status', 'public_permission'], 'evidence_claims_type_status_permission');
            $table->index(['content_task_id', 'evidence_status'], 'evidence_claims_task_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evidence_claims');
    }
};
