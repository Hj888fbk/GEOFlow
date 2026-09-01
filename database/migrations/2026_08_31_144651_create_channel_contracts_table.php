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
        Schema::create('channel_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_adapter_id')->constrained('platform_adapters')->cascadeOnDelete();
            $table->string('contract_version', 40);
            $table->string('content_type', 60);
            $table->json('contract');
            $table->string('status', 30)->default('candidate')->index();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['platform_adapter_id', 'contract_version', 'content_type'],
                'channel_contracts_adapter_version_type_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_contracts');
    }
};
