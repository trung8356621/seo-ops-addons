<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Execution projects created from Draft split intentionally have no writer yet.
 * Writer must be assigned manually before generate/run.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_projects')) {
            return;
        }

        DB::connection($this->connection)->statement(
            "ALTER TABLE seo_projects MODIFY COLUMN user_id BIGINT UNSIGNED NULL COMMENT 'Người viết bài được chỉ định (null = chưa chọn)'",
        );
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_projects')) {
            return;
        }

        DB::connection($this->connection)->statement(
            "ALTER TABLE seo_projects MODIFY COLUMN user_id BIGINT UNSIGNED NOT NULL COMMENT 'Người viết bài được chỉ định'",
        );
    }
};
