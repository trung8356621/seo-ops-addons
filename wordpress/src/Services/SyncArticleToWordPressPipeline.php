<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Services\SideEffect\WordPressExecutionContext;
use Omnichannel\Addons\WordPress\Services\WordPressArticleSyncService;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;

/**
 * Article/product WordPress sync only — no product review orchestration.
 */
final class SyncArticleToWordPressPipeline
{
    public function __construct(
        private readonly WordPressArticleSyncService $articleSync,
    ) {}

    /**
     * @param  array{seo_title?: string, meta_description?: string, focus_keyword?: string}|null  $seoOverride
     * @return array<string, mixed>
     */
    public function run(
        SeoArticle $article,
        WordPressExecutionContext $sideEffect,
        string $mode = 'sync',
        ?array $seoOverride = null,
        ?string $slug = null,
    ): array {
        $result = match ($mode) {
            'publish' => $this->articleSync->publishForArticle($article, $sideEffect, $seoOverride),
            'update_existing' => $this->articleSync->syncForArticle($article, $sideEffect, $seoOverride),
            'seo_meta' => $this->articleSync->syncSeoMetaForArticle($article, $sideEffect, $seoOverride ?? []),
            'slug' => $this->articleSync->syncSlugForArticle(
                $article,
                $sideEffect,
                $slug ?? (string) ($article->slug ?? ''),
            ),
            default => $this->syncOrCreateStandalone($article, $sideEffect, $seoOverride),
        };

        if (! ($result['success'] ?? false)) {
            return $result;
        }

        $article = $article->fresh() ?? $article;
        $wpPostId = (int) ($result['wp_post_id'] ?? $article->wordpressLink?->wp_post_id ?? 0);

        return array_merge($result, [
            'article_id' => (int) $article->id,
            'post_type' => ArticlePostTypeResolver::resolve($article),
            'wp_post_id' => $wpPostId > 0 ? $wpPostId : null,
            'wordpress_connection_id' => (int) ($article->site_id ?? 0) ?: null,
            'sync_status' => 'completed',
        ]);
    }

    /**
     * Default `sync` (standalone / retry / automation): create WP post when unlinked.
     * `update_existing` stays editor-sync only — never create.
     *
     * @param  array{seo_title?: string, meta_description?: string, focus_keyword?: string}|null  $seoOverride
     * @return array<string, mixed>
     */
    private function syncOrCreateStandalone(
        SeoArticle $article,
        WordPressExecutionContext $sideEffect,
        ?array $seoOverride = null,
    ): array {
        if ((int) ($article->wordpressLink?->wp_post_id ?? 0) <= 0) {
            return $this->articleSync->publishForArticle($article, $sideEffect, $seoOverride);
        }

        return $this->articleSync->syncForArticle($article, $sideEffect, $seoOverride);
    }
}
