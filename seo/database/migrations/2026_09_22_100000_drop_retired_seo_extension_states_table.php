<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Tombstone: drop retired Extension state table.
 *
 * Runtime authority is Cache via ExtensionStateStore — not this table.
 * Idempotent no-op when already absent (current operator DBs are clean).
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('seo_extension_states')) {
            Schema::connection($this->connection)->drop('seo_extension_states');
        }
    }

    public function down(): void
    {
        // Intentionally empty — table is permanently retired.
    }
};
