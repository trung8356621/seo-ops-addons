<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keyword Group business layer.
 *
 * Adds tables and a nullable seo_topics.keyword_group_id only.
 * Does not copy Topics into Groups, move memberships, or touch DNA.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seo_keyword_groups')) {
            $schema->create('seo_keyword_groups', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id');
                $table->string('name', 255);
                $table->string('source', 32);
                $table->unsignedBigInteger('representative_keyword_id')->nullable();
                $table->string('semantic_group_ref', 128)->nullable();
                $table->string('algorithm', 64)->nullable();
                $table->string('input_hash', 64)->nullable();
                $table->boolean('is_locked')->default(false);
                $table->timestamps();

                $table->index(['site_id'], 'seo_keyword_groups_site_idx');
                $table->index(['site_id', 'source'], 'seo_keyword_groups_site_source_idx');
            });
        }

        if (! $schema->hasTable('seo_keyword_group_keywords')) {
            $schema->create('seo_keyword_group_keywords', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id');
                $table->unsignedBigInteger('group_id');
                $table->unsignedBigInteger('keyword_id');
                $table->string('source', 32);
                $table->decimal('similarity_score', 8, 4)->nullable();
                $table->timestamps();

                $table->unique(['site_id', 'keyword_id'], 'seo_kw_group_keywords_site_kw_uq');
                $table->index(['site_id', 'group_id'], 'seo_kw_group_keywords_site_group_idx');
                $table->index(['group_id'], 'seo_kw_group_keywords_group_idx');
            });
        }

        if ($schema->hasTable('seo_topics') && ! $schema->hasColumn('seo_topics', 'keyword_group_id')) {
            $schema->table('seo_topics', function (Blueprint $table): void {
                $table->unsignedBigInteger('keyword_group_id')->nullable();
                $table->index(['keyword_group_id'], 'seo_topics_keyword_group_idx');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('seo_topics') && $schema->hasColumn('seo_topics', 'keyword_group_id')) {
            $schema->table('seo_topics', function (Blueprint $table): void {
                $table->dropIndex('seo_topics_keyword_group_idx');
                $table->dropColumn('keyword_group_id');
            });
        }

        $schema->dropIfExists('seo_keyword_group_keywords');
        $schema->dropIfExists('seo_keyword_groups');
    }
};
