<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Topic-level MCP quarantine flag (soft). Existing Topics default to included.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_topics')) {
            return;
        }

        if (! $schema->hasColumn('seo_topics', 'mcp_excluded')) {
            $schema->table('seo_topics', function (Blueprint $table): void {
                $table->boolean('mcp_excluded')->default(false)->after('is_locked');
                $table->index(['site_id', 'mcp_excluded'], 'seo_topics_site_mcp_excluded_idx');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_topics') || ! $schema->hasColumn('seo_topics', 'mcp_excluded')) {
            return;
        }

        $schema->table('seo_topics', function (Blueprint $table): void {
            $table->dropIndex('seo_topics_site_mcp_excluded_idx');
            $table->dropColumn('mcp_excluded');
        });
    }
};
