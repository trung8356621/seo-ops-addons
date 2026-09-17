<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop legacy Keyword Intelligence workspace/topic tables and soft FK columns.
 * Internal cleanup — down() intentionally empty.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        // Legacy KI (children first)
        $schema->dropIfExists('seo_keyword_content_project_links');
        $schema->dropIfExists('seo_keyword_project_conversions');
        $schema->dropIfExists('seo_topical_link_suggestions');
        $schema->dropIfExists('seo_keyword_article_mappings');
        $schema->dropIfExists('seo_keyword_relationships');
        $schema->dropIfExists('seo_topic_cluster_links');
        $schema->dropIfExists('seo_serp_cluster_evidence');
        $schema->dropIfExists('seo_keyword_analysis_operations');
        $schema->dropIfExists('seo_topical_map_versions');
        $schema->dropIfExists('seo_keywords');
        $schema->dropIfExists('seo_keyword_clusters');
        $schema->dropIfExists('seo_topics');
        $schema->dropIfExists('seo_keyword_workspaces');

        // MCP topic groups
        $schema->dropIfExists('seo_mcp_topic_group_members');
        $schema->dropIfExists('seo_mcp_topic_groups');

        // Derived topic
        $schema->dropIfExists('seo_keyword_dna');
        $schema->dropIfExists('seo_topic_cluster_aliases');
        $schema->dropIfExists('seo_topic_cluster_meta');
        $schema->dropIfExists('seo_keyword_classifications');

        // Soft FK columns on surviving tables (keep keyword_id on serp queries)
        $this->dropColumnsIfPresent($schema, 'seo_serp_queries', ['workspace_id', 'cluster_id']);
        $this->dropColumnsIfPresent($schema, 'seo_serp_content_gaps', ['workspace_id', 'cluster_id']);
        $this->dropColumnsIfPresent($schema, 'seo_gsc_query_mappings', ['cluster_id', 'topic_id']);
    }

    public function down(): void
    {
        // Internal cleanup — recreate stubs not required.
    }

    /**
     * @param  \Illuminate\Database\Schema\Builder  $schema
     * @param  list<string>  $columns
     */
    private function dropColumnsIfPresent($schema, string $table, array $columns): void
    {
        if (! $schema->hasTable($table)) {
            return;
        }

        $present = array_values(array_filter(
            $columns,
            static fn (string $column): bool => $schema->hasColumn($table, $column),
        ));
        if ($present === []) {
            return;
        }

        $schema->table($table, function (Blueprint $blueprint) use ($present): void {
            $blueprint->dropColumn($present);
        });
    }
};
