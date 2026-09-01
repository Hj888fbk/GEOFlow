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
        Schema::create('content_masters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_task_id')->constrained('content_tasks')->cascadeOnDelete();
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->foreignId('prompt_recipe_version_id')->nullable()->constrained('prompt_recipe_versions')->nullOnDelete();
            $table->string('schema_version', 80)->default('hengjia-content-package/v1');
            $table->string('title');
            $table->string('h1');
            $table->string('slug', 220);
            $table->text('summary')->nullable();
            $table->text('meta_description')->nullable();
            $table->json('package');
            $table->json('article_snapshot')->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->json('blockers')->nullable();
            $table->char('package_hash', 64)->index();
            $table->string('provider', 80)->nullable();
            $table->string('model', 160)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->timestamps();

            $table->index(['content_task_id', 'status'], 'content_masters_task_status');
            $table->index(['slug', 'status'], 'content_masters_slug_status');
            $table->index(['article_id', 'status'], 'content_masters_article_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_masters');
    }
};
