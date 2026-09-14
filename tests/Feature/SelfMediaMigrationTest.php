<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SelfMediaMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_media_migration_is_reversible_and_restores_all_columns(): void
    {
        $path = database_path('migrations/2026_09_07_000000_create_self_media_publication_planning_tables.php');
        $migration = require $path;
        $portableDocumentsPath = database_path('migrations/2026_09_08_000000_add_portable_self_media_documents.php');
        $portableDocumentsMigration = require $portableDocumentsPath;
        $unifiedPublicationCenterPath = database_path('migrations/2026_09_12_120000_unify_manual_publication_center.php');
        $unifiedPublicationCenterMigration = require $unifiedPublicationCenterPath;

        $this->assertTrue(Schema::hasTable('manual_publication_batches'));
        $this->assertTrue(Schema::hasColumn('manual_publication_batches', 'model_access_admin_id'));
        $this->assertTrue(Schema::hasColumn('manual_publication_batches', 'execution_lease_token'));
        $this->assertTrue(Schema::hasColumn('manual_publications', 'source_hash'));

        // 回滚顺序必须与 migrate:rollback 一致（倒序）：09-12 → 09-08 → 09-07。
        // 09-08 建的 self_media_media_snapshots 带有指向 manual_publication_batches 的外键，
        // 跳过它直接 down 09-07 会被 PostgreSQL 依赖约束拒绝。
        $unifiedPublicationCenterMigration->down();
        $portableDocumentsMigration->down();
        $migration->down();

        $this->assertFalse(Schema::hasTable('manual_publication_batches'));
        $this->assertFalse(Schema::hasTable('website_publication_receipts'));
        $this->assertFalse(Schema::hasTable('self_media_policies'));
        $this->assertFalse(Schema::hasColumn('manual_publications', 'source_hash'));
        $this->assertFalse(Schema::hasColumn('manual_publication_accounts', 'editor_url'));

        $migration->up();
        $portableDocumentsMigration->up();
        $unifiedPublicationCenterMigration->up();

        $this->assertTrue(Schema::hasTable('manual_publication_batches'));
        $this->assertTrue(Schema::hasColumn('manual_publication_batches', 'requested_ai_model_snapshot'));
        $this->assertTrue(Schema::hasColumn('manual_publication_batches', 'lease_expires_at'));
        $this->assertTrue(Schema::hasTable('website_publication_receipts'));
        $this->assertTrue(Schema::hasTable('self_media_policies'));
        $this->assertTrue(Schema::hasColumn('manual_publications', 'source_hash'));
        $this->assertTrue(Schema::hasColumn('manual_publications', 'deleted_at'));
        $this->assertTrue(Schema::hasColumn('manual_publication_accounts', 'editor_url'));
        $this->assertTrue(Schema::hasTable('browser_operator_clients'));
        $this->assertTrue(Schema::hasTable('manual_publication_account_sessions'));
    }
}
