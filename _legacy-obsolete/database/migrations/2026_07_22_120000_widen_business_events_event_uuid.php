<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * wordpress.synced dedupe dùng sync_operation_id = sha256 hex (64 chars)
 * làm event_uuid. Cột uuid()/char(36) bị 1406 trên MySQL strict.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('business_events')) {
            return;
        }

        // Giữ unique index hiện có; chỉ nới độ dài cột.
        DB::connection($this->connection)->statement(
            'ALTER TABLE `business_events` MODIFY `event_uuid` VARCHAR(64) NOT NULL'
        );
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('business_events')) {
            return;
        }

        DB::connection($this->connection)->statement(
            'ALTER TABLE `business_events` MODIFY `event_uuid` CHAR(36) NOT NULL'
        );
    }
};
