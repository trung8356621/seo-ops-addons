<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_link_maps')) {
            return;
        }

        DB::connection($this->connection)->statement(
            "ALTER TABLE seo_link_maps MODIFY COLUMN status ENUM('active', 'needs_audit', 'ignored', 'broken') NOT NULL DEFAULT 'active'",
        );
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_link_maps')) {
            return;
        }

        DB::connection($this->connection)->statement(
            "UPDATE seo_link_maps SET status = 'active' WHERE status = 'broken'",
        );

        DB::connection($this->connection)->statement(
            "ALTER TABLE seo_link_maps MODIFY COLUMN status ENUM('active', 'needs_audit', 'ignored') NOT NULL DEFAULT 'active'",
        );
    }
};
