<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * External hrefs (esp. Facebook click wrappers + tracking) exceed VARCHAR(255).
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_link_maps')) {
            return;
        }

        if (! Schema::connection($this->connection)->hasColumn('seo_link_maps', 'target_external_url')) {
            return;
        }

        DB::connection($this->connection)->statement(
            'ALTER TABLE seo_link_maps MODIFY target_external_url VARCHAR(2048) NULL'
        );
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_link_maps')) {
            return;
        }

        if (! Schema::connection($this->connection)->hasColumn('seo_link_maps', 'target_external_url')) {
            return;
        }

        // Truncate oversize values before shrinking — otherwise MySQL may reject the MODIFY.
        DB::connection($this->connection)->statement(
            'UPDATE seo_link_maps
             SET target_external_url = LEFT(target_external_url, 255)
             WHERE target_external_url IS NOT NULL AND CHAR_LENGTH(target_external_url) > 255'
        );

        DB::connection($this->connection)->statement(
            'ALTER TABLE seo_link_maps MODIFY target_external_url VARCHAR(255) NULL'
        );
    }
};
