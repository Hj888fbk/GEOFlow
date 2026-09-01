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
        Schema::create('content_generation_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('run_key')->unique();
            $table->foreignId('content_task_id')->constrained('content_tasks')->cascadeOnDelete();
            $table->foreignId('content_master_id')->nullable()->constrained('content_masters')->nullOnDelete();
            $table->foreignId('prompt_recipe_version_id')->nullable()->constrained('prompt_recipe_versions')->nullOnDelete();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('status', 30)->default('running')->index();
            $table->char('input_hash', 64)->index();
            $table->char('output_hash', 64)->nullable()->index();
            $table->string('provider', 80)->nullable();
            $table->string('model', 160)->nullable();
            $table->json('input_snapshot');
            $table->json('validation_result')->nullable();
            $table->json('token_usage')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['content_task_id', 'status', 'started_at'], 'content_generation_runs_task_status_started');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_generation_runs');
    }
};
