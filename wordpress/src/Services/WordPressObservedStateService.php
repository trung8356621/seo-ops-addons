<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Models\WordpressArticleLink;
use Omnichannel\Addons\WordPress\Support\ObservedWordPressPostStatus;

/**
 * Persist observed WP post facts on wordpress_article_links (not articles).
 */
final class WordPressObservedStateService
{
    public const RECONCILE_ALIGNED = 'aligned';

    public const RECONCILE_REPAIRED = 'repaired';

    public const RECONCILE_NEEDS_ATTENTION = 'needs_attention';

    /**
     * @param  array{
     *     observed_wp_post_id?: int|null,
     *     observed_post_status?: string|null,
     *     observed_permalink?: string|null,
     *     observed_modified_at?: \DateTimeInterface|string|null,
     *     reconcile_status?: string|null
     * }  $facts
     */
    public function persist(SeoArticle $article, array $facts): void
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('wordpress_article_links')) {
            return;
        }

        $status = ObservedWordPressPostStatus::normalize(
            isset($facts['observed_post_status']) ? (string) $facts['observed_post_status'] : null,
        );
        $wpPostId = isset($facts['observed_wp_post_id']) ? (int) $facts['observed_wp_post_id'] : 0;
        $permalink = trim((string) ($facts['observed_permalink'] ?? ''));

        $payload = [
            'site_id' => (int) ($article->site_id ?? 0) ?: null,
            'observed_post_status' => $status,
            'observed_at' => now(),
        ];
        if ($this->hasColumn('observed_permalink')) {
            $payload['observed_permalink'] = $permalink !== '' ? $permalink : null;
        }
        if ($this->hasColumn('observed_modified_at') && array_key_exists('observed_modified_at', $facts)) {
            $payload['observed_modified_at'] = $facts['observed_modified_at'];
        }
        if ($this->hasColumn('reconcile_status') && isset($facts['reconcile_status'])) {
            $payload['reconcile_status'] = (string) $facts['reconcile_status'];
        }
        if ($wpPostId > 0) {
            $payload['wp_post_id'] = $wpPostId;
        }

        app(WordpressArticleLinkWriter::class)->upsert($article, $payload);
    }

    /**
     * @return array{
     *     observed_wp_post_id: int|null,
     *     observed_post_status: string|null,
     *     observed_permalink: string|null,
     *     observed_modified_at: mixed,
     *     observed_at: mixed,
     *     reconcile_status: string|null
     * }
     */
    public function read(SeoArticle $article): array
    {
        $link = $article->relationLoaded('wordpressLink')
            ? $article->wordpressLink
            : WordpressArticleLink::query()->where('article_id', (int) $article->getKey())->first();

        if (! $link instanceof WordpressArticleLink) {
            return [
                'observed_wp_post_id' => null,
                'observed_post_status' => null,
                'observed_permalink' => null,
                'observed_modified_at' => null,
                'observed_at' => null,
                'reconcile_status' => null,
            ];
        }

        return [
            'observed_wp_post_id' => (int) ($link->wp_post_id ?? 0) ?: null,
            'observed_post_status' => $link->observed_post_status !== null
                ? (string) $link->observed_post_status
                : null,
            'observed_permalink' => $link->observed_permalink !== null
                ? (string) $link->observed_permalink
                : null,
            'observed_modified_at' => $link->observed_modified_at ?? null,
            'observed_at' => $link->observed_at ?? null,
            'reconcile_status' => $link->reconcile_status !== null
                ? (string) $link->reconcile_status
                : null,
        ];
    }

    private function hasColumn(string $column): bool
    {
        return Schema::connection('omi_seo_ai')->hasColumn('wordpress_article_links', $column);
    }
}
