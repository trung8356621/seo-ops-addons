<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        if (! $schema->hasTable('seo_topic_cluster_meta')) {
            return;
        }

        $schema->table('seo_topic_cluster_meta', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('seo_topic_cluster_meta', 'mcp_excluded')) {
                $table->boolean('mcp_excluded')->default(false)->after('canonical_source');
            }
            if (! $schema->hasColumn('seo_topic_cluster_meta', 'seo_excluded')) {
                $table->boolean('seo_excluded')->default(false)->after('mcp_excluded');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        if (! $schema->hasTable('seo_topic_cluster_meta')) {
            return;
        }

        $schema->table('seo_topic_cluster_meta', function (Blueprint $table) use ($schema): void {
            if ($schema->hasColumn('seo_topic_cluster_meta', 'seo_excluded')) {
                $table->dropColumn('seo_excluded');
            }
            if ($schema->hasColumn('seo_topic_cluster_meta', 'mcp_excluded')) {
                $table->dropColumn('mcp_excluded');
            }
        });
    }
};
