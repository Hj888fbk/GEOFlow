<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProbeRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_database_migrates_core_publication_tables(): void
    {
        $this->assertTrue(Schema::hasTable('migrations'));
        $this->assertTrue(Schema::hasTable('manual_publications'));
        $this->assertTrue(Schema::hasTable('manual_publication_accounts'));
        $this->assertTrue(Schema::hasTable('browser_operator_clients'));
    }
}
