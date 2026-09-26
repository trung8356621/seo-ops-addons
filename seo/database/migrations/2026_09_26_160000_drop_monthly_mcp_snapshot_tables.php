<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Retire disposable Monthly MCP period/snapshot/report tables.
 * Historical create migration 2026_08_15_140000_create_seo_mcp_monthly_tables is kept.
 *
 * Does NOT touch real GSC persistence (seo_gsc_*).
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        // FK-safe order: reports → snapshots → periods
        $schema->dropIfExists('seo_mcp_reports');
        $schema->dropIfExists('seo_mcp_source_snapshots');
        $schema->dropIfExists('seo_mcp_periods');
    }

    public function down(): void
    {
        // Intentionally empty — Monthly MCP subsystem is retired; recreate from historical migration if needed.
    }
};
