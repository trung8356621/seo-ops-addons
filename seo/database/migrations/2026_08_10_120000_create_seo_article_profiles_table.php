<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SEO-owned article profile. Backfills from articles.* then dual-write era begins.
 * articles.seo_* / indexed_* remain compatibility projections until drop migration.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seo_article_profiles')) {
            $schema->create('seo_article_profiles', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('article_id')->unique();
                $table->decimal('seo_score', 5, 2)->nullable();
                $table->boolean('skip_seo_score')->default(false);
                $table->unsignedInteger('internal_link_count')->default(0);
                $table->unsignedInteger('external_link_count')->default(0);
                $table->timestamp('indexed_at')->nullable()->index();
                $table->timestamp('previous_indexed_at')->nullable();
                $table->timestamps();

                $table->foreign('article_id')
                    ->references('id')
                    ->on('articles')
                    ->cascadeOnDelete();
            });
        }

        if (! $schema->hasTable('articles') || ! $schema->hasTable('seo_article_profiles')) {
            return;
        }

        $existing = (int) DB::connection($this->connection)->table('seo_article_profiles')->count();
        if ($existing > 0) {
            return;
        }

        $cols = [
            'id as article_id',
            'seo_score',
            'skip_seo_score',
            'internal_link_count',
            'external_link_count',
            'indexed_at',
            'previous_indexed_at',
            'created_at',
            'updated_at',
        ];

        DB::connection($this->connection)->statement(
            'INSERT INTO seo_article_profiles
                (article_id, seo_score, skip_seo_score, internal_link_count, external_link_count, indexed_at, previous_indexed_at, created_at, updated_at)
             SELECT id, seo_score, skip_seo_score, internal_link_count, external_link_count, indexed_at, previous_indexed_at, created_at, updated_at
             FROM articles'
        );
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_article_profiles');
    }
};
