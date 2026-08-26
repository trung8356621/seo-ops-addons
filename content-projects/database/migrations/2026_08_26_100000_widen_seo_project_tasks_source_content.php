<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Align source_content with keyword/title (500). Historical create used default VARCHAR(255),
 * which truncates when AI New Content persists longer product/post keywords.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_project_tasks')) {
            return;
        }

        DB::connection($this->connection)->statement(
            "ALTER TABLE seo_project_tasks MODIFY COLUMN source_content VARCHAR(500) NOT NULL COMMENT 'Từ khóa hoặc tiêu đề bài cần sửa'",
        );
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_project_tasks')) {
            return;
        }

        DB::connection($this->connection)->statement(
            "ALTER TABLE seo_project_tasks MODIFY COLUMN source_content VARCHAR(255) NOT NULL COMMENT 'Từ khóa hoặc tiêu đề bài cần sửa'",
        );
    }
};
