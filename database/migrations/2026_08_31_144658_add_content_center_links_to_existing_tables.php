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
        Schema::table('manual_publication_accounts', function (Blueprint $table): void {
            $table->foreignId('platform_adapter_id')->nullable()->constrained('platform_adapters')->nullOnDelete();
            $table->foreignId('distribution_channel_id')->nullable()->constrained('distribution_channels')->nullOnDelete();
            $table->string('connection_mode', 40)->default('browser_assisted')->index();
            $table->string('subject_name', 160)->nullable();
            $table->text('brand_voice')->nullable();
            $table->text('person_voice')->nullable();
            $table->json('content_types')->nullable();
            $table->string('authorization_status', 40)->default('not_connected')->index();
            $table->string('login_status', 40)->default('not_verified')->index();
            $table->char('external_account_hash', 64)->nullable()->index();
            $table->json('capability_snapshot')->nullable();
            $table->string('adapter_version', 40)->nullable();
            $table->json('publishing_rules')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('authorization_expires_at')->nullable();
            $table->timestamp('disabled_at')->nullable()->index();
            $table->string('last_error_code', 100)->nullable();
            $table->text('last_error_message')->nullable();

            $table->unique(
                ['platform_adapter_id', 'external_account_hash'],
                'manual_publication_accounts_adapter_account_unique'
            );
        });

        Schema::table('articles', function (Blueprint $table): void {
            $table->foreignId('content_master_id')->nullable()->constrained('content_masters')->nullOnDelete();
            $table->string('content_package_version', 80)->nullable();
            $table->char('content_package_hash', 64)->nullable()->index();
        });

        Schema::table('manual_publications', function (Blueprint $table): void {
            $table->foreignId('channel_variant_id')->nullable()->constrained('channel_variants')->nullOnDelete();
            $table->foreignId('platform_adapter_id')->nullable()->constrained('platform_adapters')->nullOnDelete();
            $table->unique('channel_variant_id', 'manual_publications_channel_variant_unique');
        });

        Schema::table('article_distributions', function (Blueprint $table): void {
            $table->foreignId('channel_variant_id')->nullable()->constrained('channel_variants')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('article_distributions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('channel_variant_id');
        });

        Schema::table('manual_publications', function (Blueprint $table): void {
            $table->dropUnique('manual_publications_channel_variant_unique');
            $table->dropConstrainedForeignId('platform_adapter_id');
            $table->dropConstrainedForeignId('channel_variant_id');
        });

        Schema::table('articles', function (Blueprint $table): void {
            $table->dropIndex(['content_package_hash']);
            $table->dropConstrainedForeignId('content_master_id');
            $table->dropColumn(['content_package_version', 'content_package_hash']);
        });

        Schema::table('manual_publication_accounts', function (Blueprint $table): void {
            $table->dropUnique('manual_publication_accounts_adapter_account_unique');
            $table->dropIndex(['connection_mode']);
            $table->dropIndex(['authorization_status']);
            $table->dropIndex(['login_status']);
            $table->dropIndex(['external_account_hash']);
            $table->dropIndex(['disabled_at']);
            $table->dropConstrainedForeignId('distribution_channel_id');
            $table->dropConstrainedForeignId('platform_adapter_id');
            $table->dropColumn([
                'connection_mode',
                'subject_name',
                'brand_voice',
                'person_voice',
                'content_types',
                'authorization_status',
                'login_status',
                'external_account_hash',
                'capability_snapshot',
                'adapter_version',
                'publishing_rules',
                'last_verified_at',
                'authorization_expires_at',
                'disabled_at',
                'last_error_code',
                'last_error_message',
            ]);
        });
    }
};
