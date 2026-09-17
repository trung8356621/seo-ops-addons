<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site-scoped Topic Core (post cluster-key retirement).
 *
 * keywords              = global dictionary
 * seo_site_keywords     = per-site classification (no topic membership)
 * seo_topics            = per-site Topic entity (name is sole name SSOT)
 * seo_topic_keywords    = membership keyword ↔ Topic (UNIQUE site+keyword)
 * seo_topic_keyword_dna = DNA of keyword within Topic
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seo_site_keywords')) {
            $schema->create('seo_site_keywords', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id');
                $table->unsignedBigInteger('keyword_id');
                $table->string('phrase_kind', 32)->nullable();
                $table->string('seo_intent', 32)->nullable();
                $table->boolean('is_seo_keyword')->default(false);
                $table->boolean('is_anchor_candidate')->default(false);
                $table->boolean('is_ambiguous')->default(false);
                $table->decimal('keyword_score', 8, 4)->nullable();
                $table->decimal('confidence', 5, 2)->nullable();
                $table->string('review_state', 32)->nullable();
                $table->string('source', 32)->nullable();
                $table->timestamps();

                $table->unique(['site_id', 'keyword_id'], 'seo_site_keywords_site_kw_uq');
                $table->index(['site_id', 'keyword_id'], 'seo_site_keywords_site_kw_idx');
                $table->index(['site_id', 'is_seo_keyword'], 'seo_site_keywords_site_seo_idx');
            });
        }

        if (! $schema->hasTable('seo_topics')) {
            $schema->create('seo_topics', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id');
                $table->string('name', 255);
                $table->string('status', 32)->default('active');
                $table->boolean('is_locked')->default(false);
                $table->timestamps();

                $table->index(['site_id'], 'seo_topics_site_idx');
                $table->index(['site_id', 'status'], 'seo_topics_site_status_idx');
            });
        }

        if (! $schema->hasTable('seo_topic_keywords')) {
            $schema->create('seo_topic_keywords', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id');
                $table->unsignedBigInteger('topic_id');
                $table->unsignedBigInteger('keyword_id');
                $table->string('source', 32)->default('recluster');
                $table->boolean('is_seed')->default(false);
                $table->boolean('is_locked')->default(false);
                $table->decimal('confidence', 5, 2)->nullable();
                $table->timestamps();

                $table->unique(['site_id', 'keyword_id'], 'seo_topic_keywords_site_kw_uq');
                $table->index(['site_id', 'topic_id'], 'seo_topic_keywords_site_topic_idx');
                $table->index(['topic_id', 'keyword_id'], 'seo_topic_keywords_topic_kw_idx');
            });
        }

        if (! $schema->hasTable('seo_topic_keyword_dna')) {
            $schema->create('seo_topic_keyword_dna', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id');
                $table->unsignedBigInteger('topic_id');
                $table->unsignedBigInteger('keyword_id');
                $table->string('value', 120);
                $table->string('facet_type', 32)->nullable();
                $table->string('placement', 16)->nullable();
                $table->string('confidence', 20)->nullable();
                $table->string('source', 32)->nullable();
                $table->timestamps();

                $table->index(['site_id', 'topic_id'], 'seo_topic_kw_dna_site_topic_idx');
                $table->index(['site_id', 'keyword_id'], 'seo_topic_kw_dna_site_kw_idx');
                $table->index(['topic_id', 'keyword_id'], 'seo_topic_kw_dna_topic_kw_idx');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('seo_topic_keyword_dna');
        $schema->dropIfExists('seo_topic_keywords');
        $schema->dropIfExists('seo_topics');
        $schema->dropIfExists('seo_site_keywords');
    }
};
