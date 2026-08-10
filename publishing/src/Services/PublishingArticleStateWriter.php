<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Services;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Publishing\Models\PublishingArticleState;

/**
 * Publishing-owned writer for publishing_article_states + articles.published_at projection.
 */
final class PublishingArticleStateWriter
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function upsert(SeoArticle $article, array $attributes): void
    {
        $articleId = (int) $article->getKey();
        if ($articleId <= 0 || ! Schema::connection('omi_seo_ai')->hasTable('publishing_article_states')) {
            return;
        }

        PublishingArticleState::query()->updateOrCreate(
            ['article_id' => $articleId],
            array_merge(['platform' => 'primary'], $attributes),
        );
    }
}
