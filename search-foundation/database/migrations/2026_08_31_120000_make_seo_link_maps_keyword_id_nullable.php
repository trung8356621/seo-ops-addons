<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Link existence != keyword existence.
 * V3 may store valid links with no SEO-eligible anchor keyword.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_link_maps')) {
            return;
        }

        if (! Schema::connection($this->connection)->hasColumn('seo_link_maps', 'keyword_id')) {
            return;
        }

        $this->dropKeywordForeignKey();

        DB::connection($this->connection)->statement(
            'ALTER TABLE seo_link_maps MODIFY keyword_id BIGINT UNSIGNED NULL'
        );

        Schema::connection($this->connection)->table('seo_link_maps', function ($table): void {
            $table->foreign('keyword_id')
                ->references('id')
                ->on('keywords')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Intentionally leave nullable — reversing would fail if null rows exist.
    }

    private function dropKeywordForeignKey(): void
    {
        $dbName = (string) DB::connection($this->connection)->getDatabaseName();
        $rows = DB::connection($this->connection)->select(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$dbName, 'seo_link_maps', 'keyword_id']
        );

        foreach ($rows as $row) {
            $name = (string) ($row->CONSTRAINT_NAME ?? '');
            if ($name === '') {
                continue;
            }
            DB::connection($this->connection)->statement(
                'ALTER TABLE seo_link_maps DROP FOREIGN KEY `'.$name.'`'
            );
        }
    }
};
