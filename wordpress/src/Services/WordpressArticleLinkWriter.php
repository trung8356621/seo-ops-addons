<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Models\WordpressArticleLink;

/**
 * WordPress-owned writer for wordpress_article_links + temporary articles.* projection.
 */
final class WordpressArticleLinkWriter
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function upsert(SeoArticle $article, array $attributes): void
    {
        $articleId = (int) $article->getKey();
        if ($articleId <= 0) {
            return;
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('wordpress_article_links')) {
            return;
        }

        $payload = $attributes;
        if (! array_key_exists('site_id', $payload)) {
            $payload['site_id'] = (int) ($article->site_id ?? 0) ?: null;
        }

        WordpressArticleLink::query()->updateOrCreate(
            ['article_id' => $articleId],
            $payload,
        );
    }
}
