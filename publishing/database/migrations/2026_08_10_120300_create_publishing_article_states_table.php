<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Publishing-owned article publication state.
 * articles.published_at remains compatibility projection until drop.
 * Content Project queue publish proof stays on seo_project_tasks.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('publishing_article_states')) {
            $schema->create('publishing_article_states', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('article_id')->unique();
                $table->string('platform', 32)->default('primary')->index();
                $table->string('publication_status', 32)->nullable()->index();
                $table->timestamp('published_at')->nullable()->index();
                $table->timestamp('last_attempt_at')->nullable();
                $table->string('last_attempt_ref', 191)->nullable();
                $table->timestamps();

                $table->foreign('article_id')
                    ->references('id')
                    ->on('articles')
                    ->cascadeOnDelete();
            });
        }

        if (! $schema->hasTable('articles') || ! $schema->hasTable('publishing_article_states')) {
            return;
        }

        if ((int) DB::connection($this->connection)->table('publishing_article_states')->count() > 0) {
            return;
        }

        DB::connection($this->connection)->statement(
            "INSERT INTO publishing_article_states
                (article_id, platform, publication_status, published_at, created_at, updated_at)
             SELECT id, 'primary', status, published_at, created_at, updated_at
             FROM articles
             WHERE published_at IS NOT NULL OR status IN ('published', 'scheduled')"
        );
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('publishing_article_states');
    }
};
