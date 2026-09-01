<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Social\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Social\Models\SeoArticleSocialLink;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

final class ArticleSocialLinkService
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_API = 'api';

    public function __construct(
        private readonly SocialSupportedDomainService $domainService,
        private readonly SocialUrlNormalizer $urlNormalizer,
    ) {}

    /**
     * @return list<array{
     *     id: int,
     *     url: string,
     *     domain: string,
     *     source: string,
     *     recorded_at: string|null,
     *     external_ref: string|null,
     * }>
     */
    public function getLinksForArticle(int $articleId): array
    {
        if ($articleId <= 0) {
            return [];
        }

        return SeoArticleSocialLink::query()
            ->where('article_id', $articleId)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get(['id', 'url', 'domain', 'source', 'recorded_at', 'external_ref'])
            ->map(static function (SeoArticleSocialLink $link): array {
                return [
                    'id' => (int) $link->getKey(),
                    'url' => (string) $link->url,
                    'domain' => (string) $link->domain,
                    'source' => (string) $link->source,
                    'recorded_at' => $link->recorded_at?->toIso8601String(),
                    'external_ref' => is_string($link->external_ref) ? $link->external_ref : null,
                ];
            })
            ->all();
    }

    public function countForArticle(int $articleId): int
    {
        if ($articleId <= 0) {
            return 0;
        }

        return (int) SeoArticleSocialLink::query()
            ->where('article_id', $articleId)
            ->count();
    }

    /**
     * @param  list<int>  $articleIds
     * @return array<int, int>
     */
    public function countsForArticles(array $articleIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $articleIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $rows = SeoArticleSocialLink::query()
            ->selectRaw('article_id, COUNT(*) as aggregate')
            ->whereIn('article_id', $ids)
            ->groupBy('article_id')
            ->pluck('aggregate', 'article_id');

        $counts = [];
        foreach ($ids as $id) {
            $counts[$id] = (int) ($rows[$id] ?? 0);
        }

        return $counts;
    }

    /**
     * @param  list<int>  $articleIds
     * @return array<int, list<array{id: int, url: string, domain: string, recorded_at: string|null}>>
     */
    public function linksGroupedByArticle(array $articleIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $articleIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $grouped = [];
        foreach ($ids as $id) {
            $grouped[$id] = [];
        }

        $links = SeoArticleSocialLink::query()
            ->whereIn('article_id', $ids)
            ->orderBy('article_id')
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get(['id', 'article_id', 'url', 'domain', 'recorded_at']);

        foreach ($links as $link) {
            $articleId = (int) ($link->article_id ?? 0);
            if ($articleId <= 0) {
                continue;
            }

            $grouped[$articleId][] = [
                'id' => (int) $link->getKey(),
                'url' => (string) $link->url,
                'domain' => (string) $link->domain,
                'recorded_at' => $link->recorded_at?->format('d/m/Y'),
            ];
        }

        return $grouped;
    }

    /**
     * @return array{
     *     saved: int,
     *     duplicate: int,
     *     unsupported: int,
     *     invalid: int,
     *     total_count: int,
     * }
     */
    public function savePastedLines(int $articleId, string $raw, ?int $createdBy = null): array
    {
        if ($articleId <= 0) {
            return $this->emptyResult(0);
        }

        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle) {
            return $this->emptyResult(0);
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $links = [];
        foreach ($lines as $line) {
            $url = trim(is_string($line) ? $line : (string) $line);
            if ($url === '') {
                continue;
            }

            $links[] = ['url' => $url];
        }

        return $this->saveBatch($article, $links, self::SOURCE_MANUAL, null, $createdBy);
    }

    /**
     * @param  list<array{url: string, external_ref?: string|null, recorded_at?: string|null}>  $links
     * @return array{
     *     saved: int,
     *     duplicate: int,
     *     unsupported: int,
     *     invalid: int,
     *     total_count: int,
     * }
     */
    public function saveBatch(
        SeoArticle $article,
        array $links,
        string $source = self::SOURCE_API,
        ?string $integrationKey = null,
        ?int $createdBy = null,
    ): array {
        $articleId = (int) $article->getKey();
        $siteId = (int) ($article->site_id ?? 0);
        if ($articleId <= 0 || $siteId <= 0) {
            return $this->emptyResult(0);
        }

        $counters = [
            'saved' => 0,
            'duplicate' => 0,
            'unsupported' => 0,
            'invalid' => 0,
        ];

        $existingHashes = $this->existingUrlHashes($articleId);
        $seenInBatch = [];
        $normalizedSource = $this->normalizeSource($source);
        $normalizedIntegrationKey = $this->normalizeIntegrationKey($integrationKey);

        foreach ($links as $link) {
            if (! is_array($link)) {
                $counters['invalid']++;

                continue;
            }

            $rawUrl = trim((string) ($link['url'] ?? ''));
            if ($rawUrl === '') {
                continue;
            }

            $normalized = $this->urlNormalizer->normalize($rawUrl);
            if ($normalized === null) {
                $counters['invalid']++;

                continue;
            }

            if (! $this->domainService->isAllowedUrl($normalized['url'])) {
                $counters['unsupported']++;

                continue;
            }

            $urlHash = $normalized['url_hash'];
            if (isset($seenInBatch[$urlHash]) || isset($existingHashes[$urlHash])) {
                $counters['duplicate']++;

                continue;
            }

            $recordedAt = $this->parseRecordedAt($link['recorded_at'] ?? null);
            $externalRef = $this->normalizeExternalRef($link['external_ref'] ?? null);

            try {
                SeoArticleSocialLink::query()->create([
                    'article_id' => $articleId,
                    'site_id' => $siteId,
                    'url' => $normalized['url'],
                    'url_hash' => $urlHash,
                    'domain' => $normalized['domain'],
                    'source' => $normalizedSource,
                    'integration_key' => $normalizedIntegrationKey,
                    'external_ref' => $externalRef,
                    'recorded_at' => $recordedAt,
                    'created_by' => $createdBy,
                ]);

                $counters['saved']++;
                $seenInBatch[$urlHash] = true;
                $existingHashes[$urlHash] = true;
            } catch (UniqueConstraintViolationException) {
                $counters['duplicate']++;
                $existingHashes[$urlHash] = true;
            }
        }

        return array_merge($counters, [
            'total_count' => $this->countForArticle($articleId),
        ]);
    }

    /**
     * @param  array{saved: int, duplicate: int, unsupported: int, invalid: int}  $counters
     * @return array{title: string, body: string|null, level: 'success'|'warning'}
     */
    public function buildSaveNotification(array $counters): array
    {
        $saved = (int) ($counters['saved'] ?? 0);
        $skipped = (int) ($counters['duplicate'] ?? 0)
            + (int) ($counters['unsupported'] ?? 0)
            + (int) ($counters['invalid'] ?? 0);

        if ($saved > 0) {
            $body = $skipped > 0
                ? __('seo-content-ai::filament.projects.archive_social_links_saved_partial', [
                    'saved' => $saved,
                    'skipped' => $skipped,
                    'unsupported' => (int) ($counters['unsupported'] ?? 0),
                    'duplicate' => (int) ($counters['duplicate'] ?? 0),
                    'invalid' => (int) ($counters['invalid'] ?? 0),
                ])
                : null;

            return [
                'title' => __('seo-content-ai::filament.projects.archive_social_links_saved', ['count' => $saved]),
                'body' => $body,
                'level' => 'success',
            ];
        }

        return [
            'title' => __('seo-content-ai::filament.projects.archive_social_links_none_saved'),
            'body' => $skipped > 0
                ? __('seo-content-ai::filament.projects.archive_social_links_all_skipped', [
                    'skipped' => $skipped,
                    'unsupported' => (int) ($counters['unsupported'] ?? 0),
                    'duplicate' => (int) ($counters['duplicate'] ?? 0),
                    'invalid' => (int) ($counters['invalid'] ?? 0),
                ])
                : __('seo-content-ai::filament.projects.archive_social_links_empty_input'),
            'level' => 'warning',
        ];
    }

    /**
     * @return array<string, true>
     */
    private function existingUrlHashes(int $articleId): array
    {
        $hashes = [];
        $rows = SeoArticleSocialLink::query()
            ->where('article_id', $articleId)
            ->pluck('url_hash');

        foreach ($rows as $hash) {
            $value = trim((string) $hash);
            if ($value !== '') {
                $hashes[$value] = true;
            }
        }

        return $hashes;
    }

    private function normalizeSource(string $source): string
    {
        $normalized = strtolower(trim($source));

        return in_array($normalized, [self::SOURCE_MANUAL, self::SOURCE_API], true)
            ? $normalized
            : self::SOURCE_API;
    }

    private function normalizeIntegrationKey(?string $integrationKey): ?string
    {
        if ($integrationKey === null) {
            return null;
        }

        $value = strtolower(trim($integrationKey));
        if ($value === '' || strlen($value) > 100) {
            return null;
        }

        if (! preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $value)) {
            return null;
        }

        return $value;
    }

    private function normalizeExternalRef(mixed $externalRef): ?string
    {
        if (! is_string($externalRef)) {
            return null;
        }

        $value = trim($externalRef);

        return $value !== '' ? mb_substr($value, 0, 191) : null;
    }

    private function parseRecordedAt(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{
     *     saved: int,
     *     duplicate: int,
     *     unsupported: int,
     *     invalid: int,
     *     total_count: int,
     * }
     */
    private function emptyResult(int $totalCount): array
    {
        return [
            'saved' => 0,
            'duplicate' => 0,
            'unsupported' => 0,
            'invalid' => 0,
            'total_count' => max(0, $totalCount),
        ];
    }
}
