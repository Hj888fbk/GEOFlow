<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tasks') || Schema::hasColumn('tasks', 'content_brief')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $table->json('content_brief')->nullable()->after('knowledge_base_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tasks') || ! Schema::hasColumn('tasks', 'content_brief')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('content_brief');
        });
    }
};
