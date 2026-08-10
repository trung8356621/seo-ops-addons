<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('articles')) {
            Schema::connection($this->connection)->table('articles', function (Blueprint $table): void {
                if (! $this->hasIndex('articles', 'articles_site_id_seo_score_index')) {
                    $table->index(['site_id', 'seo_score'], 'articles_site_id_seo_score_index');
                }
                if (! $this->hasIndex('articles', 'articles_site_id_status_type_index')) {
                    $table->index(['site_id', 'status', 'type'], 'articles_site_id_status_type_index');
                }
            });
        }

        if (Schema::connection($this->connection)->hasTable('article_meta')) {
            Schema::connection($this->connection)->table('article_meta', function (Blueprint $table): void {
                if (! $this->hasIndex('article_meta', 'article_meta_article_id_meta_key_index')) {
                    $table->index(['article_id', 'meta_key'], 'article_meta_article_id_meta_key_index');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasTable('articles')) {
            Schema::connection($this->connection)->table('articles', function (Blueprint $table): void {
                if ($this->hasIndex('articles', 'articles_site_id_seo_score_index')) {
                    $table->dropIndex('articles_site_id_seo_score_index');
                }
                if ($this->hasIndex('articles', 'articles_site_id_status_type_index')) {
                    $table->dropIndex('articles_site_id_status_type_index');
                }
            });
        }

        if (Schema::connection($this->connection)->hasTable('article_meta')) {
            Schema::connection($this->connection)->table('article_meta', function (Blueprint $table): void {
                if ($this->hasIndex('article_meta', 'article_meta_article_id_meta_key_index')) {
                    $table->dropIndex('article_meta_article_id_meta_key_index');
                }
            });
        }
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $indexes = Schema::connection($this->connection)->getIndexes($table);

        foreach ($indexes as $index) {
            if (($index['name'] ?? '') === $indexName) {
                return true;
            }
        }

        return false;
    }
};
