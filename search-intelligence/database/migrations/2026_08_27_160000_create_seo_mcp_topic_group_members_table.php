<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MCP Topic Groups — compress Site MCP projection only.
 * Does NOT merge real keyword clusters / memberships.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        if ($schema->hasTable('seo_mcp_topic_group_members')) {
            return;
        }

        $schema->create('seo_mcp_topic_group_members', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            /** Public group ref = primary cluster_key (no numeric IDs in MCP surface). */
            $table->string('group_ref', 120)->index();
            $table->string('cluster_key', 120);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['site_id', 'cluster_key'], 'seo_mcp_topic_group_members_site_cluster_uq');
            $table->index(['site_id', 'group_ref'], 'seo_mcp_topic_group_members_site_group_idx');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        if ($schema->hasTable('seo_mcp_topic_group_members')) {
            $schema->drop('seo_mcp_topic_group_members');
        }
    }
};
