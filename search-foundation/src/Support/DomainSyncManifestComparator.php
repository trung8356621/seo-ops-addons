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
     * @param  Collection<int, object{wp_post_id: int, type: string, updated_at: mixed}>  $localArticles
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
        $localArticleWpIds = [];
        foreach ($localArticles as $article) {
            $wpId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
            $type = (string) ($article->type ?? '');
            if ($wpId <= 0 || $type === '') {
                continue;
            }

            $localIndex[$this->localKey($type, $wpId)] = $article;

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

            if ($this->isTaxonomyType($type)) {
                if ($local === null) {
                    $newCount++;
                    $refs[] = $this->normalizeRef($entry);
                    $refKeys[$key] = true;
                } else {
                    $skipped++;
                }

                continue;
            }

            if ($local === null) {
                $newCount++;
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
        foreach ($localArticles as $article) {
            $wpId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
            $type = (string) ($article->type ?? '');
            if ($wpId <= 0 || $type === '') {
                continue;
            }

            $localIndex[$this->localKey($type, $wpId)] = true;
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
            if (! isset($localIndex[$key]) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
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
            if (! $this->isArticleLikeType($type)) {
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
        return in_array($type, ['article', ''], true);
    }

    private function isTaxonomyType(string $type): bool
    {
        return in_array($type, ['category', 'product_category'], true);
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
