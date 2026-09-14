<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manual_publication_batches', function (Blueprint $table): void {
            $table->foreignId('persona_id')
                ->nullable()
                ->after('task_id')
                ->constrained('manual_publication_personas')
                ->nullOnDelete();
            $table->json('target_account_ids')->nullable()->after('target_platforms');
            $table->char('account_selection_hash', 64)->nullable()->after('platform_combination_hash')->index();
            $table->timestamp('archived_at')->nullable()->after('invalidated_at')->index();
            $table->softDeletes('deleted_at', 6);
        });

        Schema::table('manual_publications', function (Blueprint $table): void {
            $table->dropUnique('manual_publications_batch_platform_unique');
            $table->index(
                ['manual_publication_batch_id', 'platform'],
                'manual_publications_batch_platform_index',
            );
            $table->unique(
                ['manual_publication_batch_id', 'account_id'],
                'manual_publications_batch_account_unique',
            );
            $table->timestamp('archived_at')->nullable()->after('completed_at')->index();
            $table->softDeletes('deleted_at', 6);
        });

        Schema::create('browser_operator_clients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('personal_access_token_id')
                ->unique()
                ->constrained('personal_access_tokens')
                ->cascadeOnDelete();
            $table->string('client_type', 24)->default('extension')->index();
            $table->string('client_name', 80);
            $table->string('client_version', 64);
            $table->json('capabilities')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('manual_publication_account_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('browser_operator_client_id')
                ->constrained('browser_operator_clients')
                ->cascadeOnDelete();
            $table->foreignId('manual_publication_account_id')
                ->constrained('manual_publication_accounts')
                ->cascadeOnDelete();
            $table->string('status', 24)->default('unknown')->index();
            $table->char('observed_account_hash', 64)->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->timestamp('checked_at')->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['browser_operator_client_id', 'manual_publication_account_id'],
                'manual_publication_account_client_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_publication_account_sessions');
        Schema::dropIfExists('browser_operator_clients');

        Schema::table('manual_publications', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->dropIndex(['archived_at']);
            $table->dropColumn('archived_at');
            $table->dropUnique('manual_publications_batch_account_unique');
            $table->dropIndex('manual_publications_batch_platform_index');
            $table->unique(
                ['manual_publication_batch_id', 'platform'],
                'manual_publications_batch_platform_unique',
            );
        });

        Schema::table('manual_publication_batches', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->dropIndex(['archived_at']);
            $table->dropIndex(['account_selection_hash']);
            $table->dropConstrainedForeignId('persona_id');
            $table->dropColumn([
                'target_account_ids',
                'account_selection_hash',
                'archived_at',
            ]);
        });
    }
};
