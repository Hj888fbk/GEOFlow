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
        Schema::create('prompt_recipe_versions', function (Blueprint $table) {
            $table->id();
            $table->string('recipe_key', 80);
            $table->string('version', 40);
            $table->string('status', 30)->default('candidate')->index();
            $table->longText('template');
            $table->json('input_contract');
            $table->json('output_contract');
            $table->text('change_notes')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('activated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->unique(['recipe_key', 'version'], 'prompt_recipe_versions_key_version_unique');
            $table->index(['recipe_key', 'status'], 'prompt_recipe_versions_key_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prompt_recipe_versions');
    }
};
