<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

        // --- 1. Extend the link_type ENUM to include the new cases ---
        // Only MySQL/MariaDB support MODIFY COLUMN ENUM. SQLite (used in unit tests)
        // stores strings without ENUM enforcement — skip for non-MySQL.
        $driver = DB::connection($this->connection)->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::connection($this->connection)->statement(
                "ALTER TABLE seo_link_maps
                 MODIFY COLUMN link_type
                 ENUM('internal','external','wiki_trust','managed_cross_site','social','contact','needs_review')
                 NOT NULL DEFAULT 'internal'"
            );
        }

        // --- 2. Add the new classification columns ---
        Schema::connection($this->connection)->table('seo_link_maps', function (Blueprint $table) {
            if (! Schema::connection($this->connection)->hasColumn('seo_link_maps', 'destination_kind')) {
                $table->string('destination_kind')->nullable()->default(null)->after('link_type');
            }

            if (! Schema::connection($this->connection)->hasColumn('seo_link_maps', 'target_site_id')) {
                $table->unsignedBigInteger('target_site_id')->nullable()->after('destination_kind');
            }

            if (! Schema::connection($this->connection)->hasColumn('seo_link_maps', 'is_semantic_eligible')) {
                $table->boolean('is_semantic_eligible')->default(true)->after('target_site_id');
            }
        });

        // --- 3. Index on target_site_id for cross-site queries ---
        Schema::connection($this->connection)->table('seo_link_maps', function (Blueprint $table) {
            // Only add the index when the column was just created (or already exists but lacks the index).
            // dropIndexIfExists is not available in all drivers, so we catch the exception.
            try {
                $table->index('target_site_id', 'seo_link_maps_target_site_id_index');
            } catch (\Exception) {
                // Index already exists — safe to ignore.
            }
        });

        // --- 4. Ensure keyword_id is nullable for raw link facts (Social, Contact) ---
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->dropKeywordForeignKeySafely();
            DB::connection($this->connection)->statement(
                'ALTER TABLE seo_link_maps MODIFY keyword_id BIGINT UNSIGNED NULL'
            );
            try {
                Schema::connection($this->connection)->table('seo_link_maps', function (Blueprint $table) {
                    $table->foreign('keyword_id')
                        ->references('id')
                        ->on('keywords')
                        ->nullOnDelete();
                });
            } catch (\Throwable) {
            }
        } else {
            try {
                Schema::connection($this->connection)->table('seo_link_maps', function (Blueprint $table) {
                    $table->unsignedBigInteger('keyword_id')->nullable()->change();
                });
            } catch (\Throwable) {
            }
        }
    }

    private function dropKeywordForeignKeySafely(): void
    {
        try {
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
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_link_maps')) {
            return;
        }

        Schema::connection($this->connection)->table('seo_link_maps', function (Blueprint $table) {
            try {
                $table->dropIndex('seo_link_maps_target_site_id_index');
            } catch (\Exception) {
                // Index may not exist.
            }

            foreach (['destination_kind', 'target_site_id', 'is_semantic_eligible'] as $column) {
                if (Schema::connection($this->connection)->hasColumn('seo_link_maps', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        // Revert link_type ENUM to original three values (MySQL/MariaDB only).
        $driver = DB::connection($this->connection)->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::connection($this->connection)->statement(
                "ALTER TABLE seo_link_maps
                 MODIFY COLUMN link_type
                 ENUM('internal','external','wiki_trust')
                 NOT NULL DEFAULT 'internal'"
            );
        }
    }
};
