<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historical: first Topic tag shape was a pivot (topic_id, tag_id).
 *
 * Superseded by 2026_09_18_160000_rebuild_seo_topic_tags_vocabulary
 * (site-scoped seo_topic_tags vocabulary + seo_topic_tag_assignments).
 * Kept for migrate:fresh history only — do not treat as current SSOT.
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
