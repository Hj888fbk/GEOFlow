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
        Schema::create('channel_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_master_id')->constrained('content_masters')->cascadeOnDelete();
            $table->foreignId('platform_adapter_id')->constrained('platform_adapters')->restrictOnDelete();
            $table->foreignId('manual_publication_account_id')->nullable()->constrained('manual_publication_accounts')->nullOnDelete();
            $table->foreignId('distribution_channel_id')->nullable()->constrained('distribution_channels')->nullOnDelete();
            $table->char('variant_key', 64)->unique();
            $table->string('channel_key', 80)->index();
            $table->string('content_type', 60);
            $table->json('payload');
            $table->string('status', 30)->default('draft')->index();
            $table->json('blockers')->nullable();
            $table->char('payload_hash', 64)->index();
            $table->string('adapter_version', 40);
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->string('remote_id', 160)->nullable();
            $table->string('remote_url', 1000)->nullable();
            $table->json('receipt')->nullable();
            $table->timestamp('last_readback_at')->nullable();
            $table->timestamps();

            $table->index(['channel_key', 'status', 'scheduled_at'], 'channel_variants_channel_status_schedule');
            $table->index(['manual_publication_account_id', 'status'], 'channel_variants_account_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_variants');
    }
};
