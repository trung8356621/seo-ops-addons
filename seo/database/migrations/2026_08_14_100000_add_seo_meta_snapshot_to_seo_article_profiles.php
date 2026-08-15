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
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_article_profiles')) {
            return;
        }

        $schema->table('seo_article_profiles', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('seo_article_profiles', 'seo_title')) {
                $table->string('seo_title', 255)->nullable()->after('seo_score');
            }
            if (! $schema->hasColumn('seo_article_profiles', 'meta_description')) {
                $table->text('meta_description')->nullable();
            }
            if (! $schema->hasColumn('seo_article_profiles', 'focus_keyword')) {
                $table->string('focus_keyword', 191)->nullable()->index();
            }
            if (! $schema->hasColumn('seo_article_profiles', 'canonical_url')) {
                $table->string('canonical_url', 500)->nullable();
            }
            if (! $schema->hasColumn('seo_article_profiles', 'is_indexable')) {
                $table->boolean('is_indexable')->nullable();
            }
            if (! $schema->hasColumn('seo_article_profiles', 'is_followable')) {
                $table->boolean('is_followable')->nullable();
            }
            if (! $schema->hasColumn('seo_article_profiles', 'schema_type')) {
                $table->string('schema_type', 64)->nullable();
            }
            if (! $schema->hasColumn('seo_article_profiles', 'source_plugin')) {
                $table->string('source_plugin', 32)->nullable();
            }
            if (! $schema->hasColumn('seo_article_profiles', 'meta_hash')) {
                $table->string('meta_hash', 64)->nullable()->index();
            }
            if (! $schema->hasColumn('seo_article_profiles', 'content_hash')) {
                $table->string('content_hash', 64)->nullable()->index();
            }
            if (! $schema->hasColumn('seo_article_profiles', 'raw_meta')) {
                $table->json('raw_meta')->nullable();
            }
            if (! $schema->hasColumn('seo_article_profiles', 'synced_at')) {
                $table->timestamp('synced_at')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_article_profiles')) {
            return;
        }

        $schema->table('seo_article_profiles', function (Blueprint $table) use ($schema): void {
            $drop = [];
            foreach ([
                'seo_title',
                'meta_description',
                'focus_keyword',
                'canonical_url',
                'is_indexable',
                'is_followable',
                'schema_type',
                'source_plugin',
                'meta_hash',
                'content_hash',
                'raw_meta',
                'synced_at',
            ] as $column) {
                if ($schema->hasColumn('seo_article_profiles', $column)) {
                    $drop[] = $column;
                }
            }
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
