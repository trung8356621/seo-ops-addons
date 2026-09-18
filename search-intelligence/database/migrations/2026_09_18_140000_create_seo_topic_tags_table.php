<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Topic user-tag attachments.
 *
 * Reuses global keyword_tags vocabulary (Tag model / TagPersistenceService).
 * Metadata only — Recluster must not rewrite these rows.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if ($schema->hasTable('seo_topic_tags')) {
            return;
        }

        $schema->create('seo_topic_tags', function (Blueprint $table): void {
            $table->unsignedBigInteger('topic_id');
            $table->unsignedBigInteger('tag_id');
            $table->timestamps();

            $table->primary(['topic_id', 'tag_id'], 'seo_topic_tags_pk');
            $table->index(['tag_id'], 'seo_topic_tags_tag_idx');
            $table->index(['topic_id'], 'seo_topic_tags_topic_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_topic_tags');
    }
};
