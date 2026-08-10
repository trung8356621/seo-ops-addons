<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Media-owned article media roles (featured first).
 * articles.featured_* remain compatibility projections until drop.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('article_media_states')) {
            $schema->create('article_media_states', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('article_id')->index();
                $table->unsignedBigInteger('media_id')->nullable()->index();
                $table->string('role', 32)->default('featured')->index();
                $table->unsignedInteger('position')->default(0);
                $table->string('source', 64)->nullable();
                $table->string('status', 16)->nullable()->index();
                $table->string('display_url', 2048)->nullable();
                $table->timestamps();

                $table->unique(['article_id', 'role'], 'article_media_states_article_role_unique');
                $table->foreign('article_id')
                    ->references('id')
                    ->on('articles')
                    ->cascadeOnDelete();
            });
        }

        if (! $schema->hasTable('articles') || ! $schema->hasTable('article_media_states')) {
            return;
        }

        $hasFeatured = (int) DB::connection($this->connection)
            ->table('article_media_states')
            ->where('role', 'featured')
            ->count();
        if ($hasFeatured > 0) {
            return;
        }

        // Legacy articles.featured_* projection columns — skipped after Task 5 drop.
        $legacyCols = [
            'featured_media_id',
            'featured_thumb_url',
            'featured_image_status',
            'featured_image_source',
        ];
        foreach ($legacyCols as $col) {
            if (! $schema->hasColumn('articles', $col)) {
                return;
            }
        }

        DB::connection($this->connection)->statement(
            "INSERT INTO article_media_states
                (article_id, media_id, role, position, source, status, display_url, created_at, updated_at)
             SELECT id, featured_media_id, 'featured', 0, featured_image_source, featured_image_status, featured_thumb_url, created_at, updated_at
             FROM articles
             WHERE featured_media_id IS NOT NULL
                OR featured_thumb_url IS NOT NULL
                OR featured_image_status IS NOT NULL
                OR featured_image_source IS NOT NULL"
        );
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('article_media_states');
    }
};
