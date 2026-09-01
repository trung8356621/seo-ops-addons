<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seo_article_social_links')
            || ! $schema->hasTable('seo_project_archive_item_social_links')
            || ! $schema->hasTable('seo_project_archive_items')
        ) {
            return;
        }

        $archiveLinks = DB::connection($this->connection)
            ->table('seo_project_archive_item_social_links as sl')
            ->join('seo_project_archive_items as ai', 'ai.id', '=', 'sl.archive_item_id')
            ->where('ai.article_id', '>', 0)
            ->orderBy('sl.id')
            ->get([
                'sl.url',
                'sl.url_hash',
                'sl.domain',
                'sl.created_by',
                'sl.created_at',
                'sl.updated_at',
                'ai.article_id',
            ]);

        foreach ($archiveLinks as $row) {
            $articleId = (int) ($row->article_id ?? 0);
            if ($articleId <= 0) {
                continue;
            }

            $siteId = (int) DB::connection($this->connection)
                ->table('articles')
                ->where('id', $articleId)
                ->value('site_id');

            if ($siteId <= 0) {
                continue;
            }

            $exists = DB::connection($this->connection)
                ->table('seo_article_social_links')
                ->where('article_id', $articleId)
                ->where('url_hash', (string) ($row->url_hash ?? ''))
                ->exists();

            if ($exists) {
                continue;
            }

            DB::connection($this->connection)->table('seo_article_social_links')->insert([
                'article_id' => $articleId,
                'site_id' => $siteId,
                'url' => (string) ($row->url ?? ''),
                'url_hash' => (string) ($row->url_hash ?? ''),
                'domain' => (string) ($row->domain ?? ''),
                'source' => 'manual',
                'integration_key' => null,
                'external_ref' => null,
                'recorded_at' => $row->created_at,
                'created_by' => $row->created_by,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->updated_at ?? now(),
            ]);
        }

        $schema->dropIfExists('seo_project_archive_item_social_links');
    }

    public function down(): void
    {
        // Forward-only: canonical storage lives on seo_article_social_links.
    }
};
