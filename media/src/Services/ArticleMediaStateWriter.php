<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\ArticleMediaState;

/**
 * Media-owned writer for article_media_states (featured role) + articles.* projection.
 */
final class ArticleMediaStateWriter
{
    /**
     * @param  array{media_id?: ?int, display_url?: ?string, status?: ?string, source?: ?string, position?: int}  $attributes
     */
    public function upsertFeatured(SeoArticle $article, array $attributes): void
    {
        $articleId = (int) $article->getKey();
        if ($articleId <= 0 || ! Schema::connection('omi_seo_ai')->hasTable('article_media_states')) {
            return;
        }

        ArticleMediaState::query()->updateOrCreate(
            [
                'article_id' => $articleId,
                'role' => 'featured',
            ],
            [
                'media_id' => $attributes['media_id'] ?? null,
                'display_url' => $attributes['display_url'] ?? null,
                'status' => $attributes['status'] ?? null,
                'source' => $attributes['source'] ?? null,
                'position' => (int) ($attributes['position'] ?? 0),
            ],
        );
    }
}
