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
        Schema::create('platform_adapters', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('name', 120);
            $table->string('version', 40);
            $table->string('execution_mode', 40);
            $table->string('implementation_class', 255)->nullable();
            $table->json('supported_content_types');
            $table->json('connection_modes');
            $table->json('capabilities');
            $table->json('field_contract');
            $table->string('status', 30)->default('active')->index();
            $table->boolean('is_builtin')->default(true)->index();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_adapters');
    }
};
