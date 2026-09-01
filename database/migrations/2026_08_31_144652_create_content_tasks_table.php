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
        Schema::create('content_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('candidate_key', 160)->nullable()->unique();
            $table->string('product_key', 120)->default('rubber-joint')->index();
            $table->string('title');
            $table->string('audience', 160);
            $table->string('intent', 160);
            $table->string('page_role', 80)->index();
            $table->string('primary_keyword', 160);
            $table->json('secondary_keywords')->nullable();
            $table->json('target_channels');
            $table->unsignedTinyInteger('priority')->default(3)->index();
            $table->date('due_on')->nullable()->index();
            $table->string('status', 30)->default('candidate')->index();
            $table->string('review_status', 30)->default('pending')->index();
            $table->json('blockers')->nullable();
            $table->char('input_hash', 64);
            $table->foreignId('assigned_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['due_on', 'status', 'priority'], 'content_tasks_due_status_priority');
            $table->index(['product_key', 'page_role', 'primary_keyword'], 'content_tasks_product_role_keyword');
            $table->index(['task_id', 'status'], 'content_tasks_existing_task_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_tasks');
    }
};
