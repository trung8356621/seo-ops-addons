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
        if (! Schema::connection($this->connection)->hasTable('seo_project_tasks')) {
            return;
        }

        $type = $this->typeColumnDefinition();
        // Already has new_title, or already normalized to create|rewrite|improve.
        if (str_contains($type, 'new_title') || str_contains($type, 'create')) {
            return;
        }

        DB::connection($this->connection)->statement(
            "ALTER TABLE seo_project_tasks MODIFY COLUMN type ENUM('rewrite', 'new_keyword', 'new_title', 'improve') NOT NULL COMMENT 'rewrite: viết lại bài lỗi, new_keyword: từ khóa mới, new_title: viết mới theo tiêu đề, improve: tối ưu thủ công'",
        );
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_project_tasks')) {
            return;
        }

        $type = $this->typeColumnDefinition();
        if (! str_contains($type, 'new_title') || str_contains($type, 'create')) {
            return;
        }

        DB::connection($this->connection)->statement(
            "UPDATE seo_project_tasks SET type = 'new_keyword' WHERE type = 'new_title'",
        );

        DB::connection($this->connection)->statement(
            "ALTER TABLE seo_project_tasks MODIFY COLUMN type ENUM('rewrite', 'new_keyword', 'improve') NOT NULL COMMENT 'rewrite: viết lại bài lỗi, new_keyword: từ khóa mới, improve: tối ưu thủ công'",
        );
    }

    private function typeColumnDefinition(): string
    {
        $row = DB::connection($this->connection)->selectOne("SHOW COLUMNS FROM seo_project_tasks LIKE 'type'");

        return strtolower((string) ($row->Type ?? ''));
    }
};
