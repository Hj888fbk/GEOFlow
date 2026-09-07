<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('self_media_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->unique()->constrained('tasks')->cascadeOnDelete();
            $table->boolean('enabled')->default(false)->index();
            $table->string('content_intent', 40)->nullable();
            $table->json('platform_override')->nullable();
            $table->string('routing_version', 40)->default('self-media-routing-v1');
            $table->unsignedSmallInteger('daily_source_limit')->default(1);
            $table->unsignedSmallInteger('pending_batch_limit')->default(2);
            $table->timestamps();
        });

        Schema::create('website_publication_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->string('responsible_project_id', 40)->default('HJ-WEB');
            $table->string('formal_url', 1000);
            $table->unsignedSmallInteger('http_status');
            $table->char('source_hash', 64);
            $table->char('readback_hash', 64);
            $table->boolean('readback_succeeded')->default(false)->index();
            $table->timestamp('verified_at');
            $table->json('receipt_payload')->nullable();
            $table->timestamps();

            $table->unique(['article_id', 'source_hash'], 'website_receipts_article_source_unique');
            $table->index(['readback_succeeded', 'verified_at'], 'website_receipts_verified_index');
        });

        Schema::create('manual_publication_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->foreignId('website_publication_receipt_id')
                ->constrained('website_publication_receipts')
                ->restrictOnDelete();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('model_access_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->enum('model_access_admin_role', ['admin', 'super_admin'])->nullable();
            $table->unsignedBigInteger('ai_config_access_version')->nullable();
            $table->foreignId('requested_ai_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->json('requested_ai_model_snapshot')->nullable();
            $table->unsignedBigInteger('resolver_policy_version')->nullable();
            $table->string('trigger', 20)->index();
            $table->string('content_intent', 40);
            $table->string('routing_version', 40);
            $table->text('routing_reason')->nullable();
            $table->json('target_platforms');
            $table->char('platform_combination_hash', 64);
            $table->string('source_url', 1000);
            $table->json('website_readback');
            $table->char('source_hash', 64)->index();
            $table->json('source_snapshot');
            $table->json('fact_constraints');
            $table->json('media_manifest')->nullable();
            $table->char('idempotency_hash', 64)->unique();
            $table->string('status', 32)->default('planned')->index();
            $table->json('generation_errors')->nullable();
            $table->uuid('execution_lease_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->unsignedInteger('generation_attempt')->default(0);
            $table->timestamp('invalidated_at')->nullable()->index();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->index(['trigger', 'created_at'], 'manual_publication_batches_trigger_date');
            $table->index(['status', 'created_at'], 'manual_publication_batches_pending');
            $table->index(
                ['status', 'execution_lease_token'],
                'manual_publication_batches_execution_lease',
            );
        });

        Schema::table('manual_publication_accounts', function (Blueprint $table): void {
            $table->string('editor_url', 1000)->nullable()->after('profile_url');
            $table->string('account_uid', 255)->nullable()->after('editor_url');
            $table->string('homepage_identifier', 255)->nullable()->after('account_uid');
            $table->boolean('browser_adapter_enabled')->default(false)->after('homepage_identifier');
        });

        Schema::table('manual_publications', function (Blueprint $table): void {
            $table->foreignId('manual_publication_batch_id')
                ->nullable()
                ->after('id')
                ->constrained('manual_publication_batches')
                ->nullOnDelete();
            $table->string('platform_title', 255)->nullable()->after('content');
            $table->text('platform_summary')->nullable()->after('platform_title');
            $table->longText('body_markdown')->nullable()->after('platform_summary');
            $table->longText('body_html')->nullable()->after('body_markdown');
            $table->json('tags')->nullable()->after('body_html');
            $table->json('media_manifest')->nullable()->after('tags');
            $table->char('source_hash', 64)->nullable()->after('media_manifest')->index();
            $table->json('draft_filled_receipt')->nullable()->after('execution_receipt');
            $table->timestamp('source_stale_at')->nullable()->after('draft_filled_receipt')->index();

            $table->unique(
                ['manual_publication_batch_id', 'platform'],
                'manual_publications_batch_platform_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('manual_publications', function (Blueprint $table): void {
            $table->dropUnique('manual_publications_batch_platform_unique');
            $table->dropIndex('manual_publications_source_hash_index');
            $table->dropIndex('manual_publications_source_stale_at_index');
            $table->dropConstrainedForeignId('manual_publication_batch_id');
            $table->dropColumn([
                'platform_title',
                'platform_summary',
                'body_markdown',
                'body_html',
                'tags',
                'media_manifest',
                'source_hash',
                'draft_filled_receipt',
                'source_stale_at',
            ]);
        });

        Schema::table('manual_publication_accounts', function (Blueprint $table): void {
            $table->dropColumn(['editor_url', 'account_uid', 'homepage_identifier', 'browser_adapter_enabled']);
        });

        Schema::dropIfExists('manual_publication_batches');
        Schema::dropIfExists('website_publication_receipts');
        Schema::dropIfExists('self_media_policies');
    }
};
