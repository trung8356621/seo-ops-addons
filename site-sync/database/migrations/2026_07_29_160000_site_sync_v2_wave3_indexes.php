<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 3 — additive indexes for Site Sync V2 reconciliation / inbound / catalog.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('seo_site_sync_runs')) {
            try {
                $schema->table('seo_site_sync_runs', function (Blueprint $table): void {
                    $table->index(['site_id', 'status'], 'seo_site_sync_runs_site_status_idx');
                });
            } catch (\Throwable) {
                // Index may already exist.
            }
        }

        if ($schema->hasTable('seo_site_sync_inbound_events')) {
            try {
                $schema->table('seo_site_sync_inbound_events', function (Blueprint $table): void {
                    $table->index(['site_id', 'status'], 'seo_inbound_site_status_idx');
                });
            } catch (\Throwable) {
            }
        }

        if ($schema->hasTable('seo_site_link_catalog')) {
            try {
                $schema->table('seo_site_link_catalog', function (Blueprint $table): void {
                    $table->index(['site_id', 'source'], 'seo_link_catalog_site_source_idx');
                });
            } catch (\Throwable) {
            }
        }

        if ($schema->hasTable('keywords') && $schema->hasColumn('keywords', 'source')) {
            try {
                $schema->table('keywords', function (Blueprint $table): void {
                    $table->index(['source'], 'keywords_source_idx');
                });
            } catch (\Throwable) {
            }
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        foreach ([
            ['seo_site_sync_runs', 'seo_site_sync_runs_site_status_idx'],
            ['seo_site_sync_inbound_events', 'seo_inbound_site_status_idx'],
            ['seo_site_link_catalog', 'seo_link_catalog_site_source_idx'],
            ['keywords', 'keywords_source_idx'],
        ] as [$table, $index]) {
            if (! $schema->hasTable($table)) {
                continue;
            }
            try {
                $schema->table($table, function (Blueprint $blueprint) use ($index): void {
                    $blueprint->dropIndex($index);
                });
            } catch (\Throwable) {
            }
        }
    }
};
