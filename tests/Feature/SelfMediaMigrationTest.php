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

        $this->assertTrue(Schema::hasTable('manual_publication_batches'));
        $this->assertTrue(Schema::hasColumn('manual_publication_batches', 'model_access_admin_id'));
        $this->assertTrue(Schema::hasColumn('manual_publication_batches', 'execution_lease_token'));
        $this->assertTrue(Schema::hasColumn('manual_publications', 'source_hash'));

        $migration->down();

        $this->assertFalse(Schema::hasTable('manual_publication_batches'));
        $this->assertFalse(Schema::hasTable('website_publication_receipts'));
        $this->assertFalse(Schema::hasTable('self_media_policies'));
        $this->assertFalse(Schema::hasColumn('manual_publications', 'source_hash'));
        $this->assertFalse(Schema::hasColumn('manual_publication_accounts', 'editor_url'));

        $migration->up();

        $this->assertTrue(Schema::hasTable('manual_publication_batches'));
        $this->assertTrue(Schema::hasColumn('manual_publication_batches', 'requested_ai_model_snapshot'));
        $this->assertTrue(Schema::hasColumn('manual_publication_batches', 'lease_expires_at'));
        $this->assertTrue(Schema::hasTable('website_publication_receipts'));
        $this->assertTrue(Schema::hasTable('self_media_policies'));
        $this->assertTrue(Schema::hasColumn('manual_publications', 'source_hash'));
        $this->assertTrue(Schema::hasColumn('manual_publication_accounts', 'editor_url'));
    }
}
