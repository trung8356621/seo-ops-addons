<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services\SiteMcp;

use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncClient;
use App\Models\Site;
use App\Support\RuntimeLogger;
use Throwable;

/**
 * Prefer live WordPress product_cat taxonomy over incomplete staging rows.
 */
final class SiteMcpProductCatLiveSource
{
    public function __construct(
        private readonly WordPressSiteSyncClient $wpClient,
    ) {}

    /**
     * @param  list<int>  $incompleteTermIds  Staging term ids missing parent_term_id
     * @return list<array<string, mixed>>
     */
    public function fetchVerifiedProductCats(Site $site, array $incompleteTermIds = []): array
    {
        $list = $this->wpClient->fetchTaxonomyTerms($site, 'product_cat', false);
        if (($list['success'] ?? false) && is_array($list['terms'] ?? null) && $list['terms'] !== []) {
            return $this->normalizeMany($list['terms'], 'taxonomy_export');
        }

        // Older plugin: refresh incomplete terms + parent chain one-by-one.
        $out = [];
        $pending = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $incompleteTermIds),
            static fn (int $id): bool => $id > 0,
        )));
        $seen = [];
        $maxFetches = 150;

        while ($pending !== [] && count($seen) < $maxFetches) {
            $termId = array_shift($pending);
            if ($termId === null || isset($seen[$termId])) {
                continue;
            }
            $seen[$termId] = true;

            $result = $this->wpClient->fetchTerm($site, 'product_cat', $termId);
            if (! ($result['success'] ?? false) || ! is_array($result['term'] ?? null)) {
                continue;
            }
            $normalized = SiteMcpProductCatIdentity::normalizeVerified(array_merge(
                $result['term'],
                [
                    'taxonomy' => 'product_cat',
                    'source' => 'taxonomy_term_refresh',
                ],
            ));
            if ($normalized === null) {
                continue;
            }
            $normalized['title'] = $normalized['name'];
            $normalized['source'] = 'taxonomy_term_refresh';
            $out[] = $normalized;

            $parentId = (int) $normalized['parent_term_id'];
            if ($parentId > 0 && ! isset($seen[$parentId])) {
                $pending[] = $parentId;
            }
        }

        return $out;
    }

    /**
     * Supersede incomplete staging rows with verified parent_term_id (including 0).
     *
     * @param  list<array<string, mixed>>  $verifiedTerms
     */
    public function backfillParentMetas(Site $site, array $verifiedTerms): int
    {
        $updated = 0;
        $siteId = (int) $site->id;

        foreach ($verifiedTerms as $term) {
            if (! is_array($term)) {
                continue;
            }
            $termId = (int) ($term['term_id'] ?? 0);
            if ($termId <= 0 || ! array_key_exists('parent_term_id', $term)) {
                continue;
            }

            try {
                $query = SeoArticle::query()
                    ->where('site_id', $siteId)
                    ->whereWpPostId($termId);
                ArticleContentClassification::scopeContentType($query, ContentType::Product);
                $article = ArticleContentClassification::scopeIsTerm($query, true)->first();
                if ($article === null) {
                    continue;
                }

                $parentId = (int) $term['parent_term_id'];
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => 'wp_parent_id'],
                    ['meta_value' => (string) $parentId],
                );
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => 'wp_taxonomy'],
                    ['meta_value' => 'product_cat'],
                );
                ArticleContentClassification::persist($article, [
                    'content_type' => ContentType::Product,
                    'wp_is_term' => true,
                    'wp_post_type' => 'product_cat',
                ]);
                if (array_key_exists('post_count', $term)) {
                    $article->articleMetas()->updateOrCreate(
                        ['meta_key' => 'wp_term_count'],
                        ['meta_value' => (string) (int) $term['post_count']],
                    );
                }
                $updated++;
            } catch (Throwable $e) {
                RuntimeLogger::warning('seo.site_mcp.product_cat_backfill_failed', [
                    'site_id' => $siteId,
                    'term_id' => $termId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $updated;
    }

    /**
     * @param  list<array<string, mixed>>  $terms
     * @return list<array<string, mixed>>
     */
    private function normalizeMany(array $terms, string $source): array
    {
        $out = [];
        foreach ($terms as $term) {
            if (! is_array($term)) {
                continue;
            }
            $normalized = SiteMcpProductCatIdentity::normalizeVerified(array_merge($term, [
                'taxonomy' => 'product_cat',
                'source' => $source,
            ]));
            if ($normalized === null) {
                continue;
            }
            $normalized['title'] = $normalized['name'] !== ''
                ? $normalized['name']
                : (string) ($term['title'] ?? '');
            $normalized['source'] = $source;
            $out[] = $normalized;
        }

        return $out;
    }
}
