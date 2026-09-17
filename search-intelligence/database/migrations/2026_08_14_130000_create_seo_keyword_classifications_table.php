<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * No-op: seo_keyword_classifications dropped by
 * 2026_09_17_100000_drop_legacy_keyword_workspace_and_topic_derived_tables.
 * Basename kept for migration history compatibility.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        // no-op — tables retired
    }

    public function down(): void
    {
        // no-op
    }
};
