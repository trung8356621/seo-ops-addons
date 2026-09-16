<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Support;

use Omnichannel\Addons\WordPress\Services\WordPressArticleTimestampService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class DomainSyncManifestComparator
{
    /**
     * @param  array<int, array<string, mixed>>  $manifestEntries
     * @param  Collection<int, object{wp_post_id: int, type: string, updated_at: mixed, wp_post_type?: string|null}>  $localArticles
     * @return array{
     *     refs: array<int, array<string, mixed>>,
     *     skipped: int,
     *     new_count: int,
     *     update_count: int
     * }
     */
    public function resolveFetchRefs(array $manifestEntries, Collection $localArticles): array
    {
        $timestampService = new WordPressArticleTimestampService;

        $localIndex = [];
        $localByWpId = [];
        $localArticleWpIds = [];
        foreach ($localArticles as $article) {
            $wpId = (int) ($article->wordpressLink?->wp_post_id ?? $article->wp_post_id ?? 0);
            $type = (string) ($article->type ?? '');
            if ($wpId <= 0 || $type === '') {
                continue;
            }

            $localIndex[$this->localKey($type, $wpId)] = $article;
            // Prefer wp_id lookup for post-like entities so page/post/article aliases match.
            if (! $this->isTaxonomyType($type)) {
                $localByWpId[$wpId] = $article;
            }

            if ($this->isArticleLikeType($type)) {
                $localArticleWpIds[$wpId] = true;
            }
        }

        $refs = [];
        $refKeys = [];
        $skipped = 0;
        $newCount = 0;
        $updateCount = 0;

        foreach ($manifestEntries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $wpId = (int) ($entry['wp_id'] ?? 0);
            $type = strtolower(trim((string) ($entry['type'] ?? '')));
            if ($wpId <= 0 || $type === '') {
                continue;
            }

            $key = $this->localKey($type, $wpId);
            $local = $localIndex[$key] ?? null;
            if ($local === null && ! $this->isTaxonomyType($type)) {
                $local = $localByWpId[$wpId] ?? null;
            }

            if ($this->isTaxonomyType($type)) {
                if ($local === null) {
                    $newCount++;
                    $refs[] = $this->normalizeRef($entry);
                    $refKeys[$key] = true;
                } else {
                    // Taxonomy: still refresh when native slug drifted.
                    if ($this->wpPostTypeDrifted($local, $entry)) {
                        $updateCount++;
                        $refs[] = $this->normalizeRef($entry);
                        $refKeys[$key] = true;
                    } else {
                        $skipped++;
                    }
                }

                continue;
            }

            if ($local === null) {
                $newCount++;
                $refs[] = $this->normalizeRef($entry);
                $refKeys[$key] = true;

                continue;
            }

            // Metadata identity drift (esp. post_type) must fetch even when body/timestamp looks stale locally.
            if ($this->wpPostTypeDrifted($local, $entry)) {
                $updateCount++;
                $refs[] = $this->normalizeRef($entry);
                $refKeys[$key] = true;

                continue;
            }

            $localUpdated = $local->updated_at instanceof Carbon
                ? $local->updated_at
                : ($local->updated_at !== null ? Carbon::parse((string) $local->updated_at) : null);

            if ($timestampService->remoteIsNewerThanLocal($localUpdated, $entry['post_modified'] ?? null)) {
                $updateCount++;
                $refs[] = $this->normalizeRef($entry);
                $refKeys[$key] = true;

                continue;
            }

            $skipped++;
        }

        foreach ($this->missingLocalArticleRefs($manifestEntries, $localArticleWpIds) as $missingRef) {
            $missingKey = $this->localKey((string) $missingRef['type'], (int) $missingRef['wp_id']);
            if (isset($refKeys[$missingKey])) {
                continue;
            }

            $newCount++;
            $refs[] = $missingRef;
            $refKeys[$missingKey] = true;
        }

        return [
            'refs' => $refs,
            'skipped' => $skipped,
            'new_count' => $newCount,
            'update_count' => $updateCount,
        ];
    }

    /**
     * Lập danh sách refs cho mọi bài/term đã có local — bỏ qua so sánh post_modified.
     *
     * @param  array<int, array<string, mixed>>  $manifestEntries
     * @param  Collection<int, object{wp_post_id: int, type: string, updated_at: mixed}>  $localArticles
     * @return array{
     *     refs: array<int, array<string, mixed>>,
     *     total: int
     * }
     */
    public function resolveMetadataRefreshRefs(array $manifestEntries, Collection $localArticles): array
    {
        $localIndex = [];
        $localByWpId = [];
        foreach ($localArticles as $article) {
            $wpId = (int) ($article->wordpressLink?->wp_post_id ?? $article->wp_post_id ?? 0);
            $type = (string) ($article->type ?? '');
            if ($wpId <= 0 || $type === '') {
                continue;
            }

            $localIndex[$this->localKey($type, $wpId)] = true;
            if (! $this->isTaxonomyType($type)) {
                $localByWpId[$wpId] = true;
            }
        }

        $refs = [];
        $seen = [];

        foreach ($manifestEntries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $wpId = (int) ($entry['wp_id'] ?? 0);
            $type = strtolower(trim((string) ($entry['type'] ?? '')));
            if ($wpId <= 0 || $type === '') {
                continue;
            }

            $key = $this->localKey($type, $wpId);
            $exists = isset($localIndex[$key])
                || (! $this->isTaxonomyType($type) && isset($localByWpId[$wpId]));
            if (! $exists || isset($seen[$key]) || isset($seen['wp:'.$wpId])) {
                continue;
            }

            $seen[$key] = true;
            $seen['wp:'.$wpId] = true;
            $refs[] = $this->normalizeRef($entry);
        }

        return [
            'refs' => $refs,
            'total' => count($refs),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $manifestEntries
     * @param  array<int, bool>  $localArticleWpIds
     * @return array<int, array<string, mixed>>
     */
    private function missingLocalArticleRefs(array $manifestEntries, array $localArticleWpIds): array
    {
        $missing = [];

        foreach ($manifestEntries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (($entry['wp_entity'] ?? 'post') !== 'post') {
                continue;
            }

            $type = strtolower(trim((string) ($entry['type'] ?? '')));
            if (! $this->isArticleLikeType($type) && ! $this->isPostLikeNative((string) ($entry['wp_post_type'] ?? ''))) {
                continue;
            }

            $wpId = (int) ($entry['wp_id'] ?? 0);
            if ($wpId <= 0 || isset($localArticleWpIds[$wpId])) {
                continue;
            }

            $missing[] = $this->normalizeRef($entry);
        }

        return $missing;
    }

    private function isArticleLikeType(string $type): bool
    {
        return in_array($type, ['article', 'post', 'page', ''], true);
    }

    private function isPostLikeNative(string $wpPostType): bool
    {
        $native = strtolower(trim($wpPostType));

        return in_array($native, ['post', 'page', ''], true);
    }

    private function isTaxonomyType(string $type): bool
    {
        return in_array($type, ['category', 'product_category', 'product_cat'], true);
    }

    /**
     * @param  object{wp_post_type?: string|null}  $local
     * @param  array<string, mixed>  $entry
     */
    private function wpPostTypeDrifted(object $local, array $entry): bool
    {
        $remote = strtolower(trim((string) ($entry['wp_post_type'] ?? '')));
        if ($remote === '') {
            return false;
        }

        $localNative = strtolower(trim((string) ($local->wp_post_type ?? '')));
        if ($localNative === '') {
            // Missing local identity meta → treat as drift so sync can backfill.
            return true;
        }

        return $localNative !== $remote;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function normalizeRef(array $entry): array
    {
        return [
            'wp_id' => (int) ($entry['wp_id'] ?? 0),
            'type' => (string) ($entry['type'] ?? ''),
            'wp_post_type' => (string) ($entry['wp_post_type'] ?? ''),
            'wp_entity' => (string) ($entry['wp_entity'] ?? 'post'),
        ];
    }

    private function localKey(string $type, int $wpId): string
    {
        return $type.'|'.$wpId;
    }
}
