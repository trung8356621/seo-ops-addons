<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rebuild Topic tags as site-scoped immutable vocabulary + assignment pivot.
 *
 * Replaces the short-lived seo_topic_tags(topic_id, tag_id) pivot shape.
 * Current SSOT: seo_topic_tags (+ seo_topic_tag_assignments). No data migration
 * from the pivot (empty / not product SoT).
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        // Drop wrong pivot shape if present (topic_id + tag_id only).
        if ($schema->hasTable('seo_topic_tags')) {
            $cols = $schema->getColumnListing('seo_topic_tags');
            $isLegacyPivot = in_array('topic_id', $cols, true)
                && in_array('tag_id', $cols, true)
                && ! in_array('site_id', $cols, true)
                && ! in_array('name', $cols, true);
            if ($isLegacyPivot) {
                $schema->drop('seo_topic_tags');
            }
        }

        if (! $schema->hasTable('seo_topic_tags')) {
            $schema->create('seo_topic_tags', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id');
                $table->string('name', 255);
                $table->string('slug', 255);
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['site_id', 'slug'], 'seo_topic_tags_site_slug_uq');
                $table->index(['site_id', 'name'], 'seo_topic_tags_site_name_idx');
            });
        }

        if (! $schema->hasTable('seo_topic_tag_assignments')) {
            $schema->create('seo_topic_tag_assignments', function (Blueprint $table): void {
                $table->unsignedBigInteger('topic_id');
                $table->unsignedBigInteger('tag_id');
                $table->timestamp('created_at')->useCurrent();

                $table->primary(['topic_id', 'tag_id'], 'seo_topic_tag_assignments_pk');
                $table->index(['tag_id'], 'seo_topic_tag_assignments_tag_idx');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('seo_topic_tag_assignments');
        $schema->dropIfExists('seo_topic_tags');
    }
};
