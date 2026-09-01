<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services;

use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Models\KeywordMeta;
use Illuminate\Support\Facades\DB;

final class KeywordMetaRepository
{
    public function get(int $keywordId, string $metaKey): ?string
    {
        if ($keywordId <= 0 || $metaKey === '') {
            return null;
        }

        $value = KeywordMeta::query()
            ->where('keyword_id', $keywordId)
            ->where('meta_key', $metaKey)
            ->value('meta_value');

        return is_string($value) ? $value : null;
    }

    public function set(int $keywordId, string $metaKey, ?string $metaValue): void
    {
        if ($keywordId <= 0 || $metaKey === '') {
            return;
        }

        if ($metaValue === null || trim($metaValue) === '') {
            $this->delete($keywordId, $metaKey);

            return;
        }

        KeywordMeta::query()->updateOrCreate(
            [
                'keyword_id' => $keywordId,
                'meta_key' => $metaKey,
            ],
            [
                'meta_value' => $metaValue,
            ],
        );
    }

    public function delete(int $keywordId, string $metaKey): void
    {
        if ($keywordId <= 0 || $metaKey === '') {
            return;
        }

        KeywordMeta::query()
            ->where('keyword_id', $keywordId)
            ->where('meta_key', $metaKey)
            ->delete();
    }

    public function deleteByPrefix(int $keywordId, string $prefix): void
    {
        if ($keywordId <= 0 || $prefix === '') {
            return;
        }

        KeywordMeta::query()
            ->where('keyword_id', $keywordId)
            ->where('meta_key', 'like', $prefix.'%')
            ->delete();
    }

    public function getSiteString(int $keywordId, int $siteId, string $suffix): ?string
    {
        return $this->get($keywordId, "site.{$siteId}.{$suffix}");
    }

    public function setSiteString(int $keywordId, int $siteId, string $suffix, ?string $value): void
    {
        $this->set($keywordId, "site.{$siteId}.{$suffix}", $value);
    }

    public function getSiteTargetUrl(int $keywordId, int $siteId): ?string
    {
        $url = trim((string) ($this->get($keywordId, KeywordMetaKey::siteTargetUrl($siteId)) ?? ''));

        return $url !== '' ? $url : null;
    }

    public function setSiteTargetUrl(int $keywordId, int $siteId, ?string $url): void
    {
        $this->set($keywordId, KeywordMetaKey::siteTargetUrl($siteId), $url);
    }

    public function getSiteSearchVolume(int $keywordId, int $siteId): ?int
    {
        $value = $this->get($keywordId, KeywordMetaKey::siteSearchVolume($siteId));

        return is_numeric($value) ? (int) $value : null;
    }

    public function setSiteSearchVolume(int $keywordId, int $siteId, ?int $volume): void
    {
        $this->set($keywordId, KeywordMetaKey::siteSearchVolume($siteId), $volume !== null ? (string) $volume : null);
    }

    public function getSiteDifficulty(int $keywordId, int $siteId): ?float
    {
        $value = $this->get($keywordId, KeywordMetaKey::siteDifficulty($siteId));

        return is_numeric($value) ? (float) $value : null;
    }

    public function setSiteDifficulty(int $keywordId, int $siteId, ?float $difficulty): void
    {
        $this->set($keywordId, KeywordMetaKey::siteDifficulty($siteId), $difficulty !== null ? (string) $difficulty : null);
    }

    public function keepOnRescrapeForSite(Keyword $keyword, int $siteId): bool
    {
        if ($siteId <= 0) {
            return false;
        }

        if (! in_array($keyword->type, [Keyword::TYPE_FREE, Keyword::TYPE_SUGGEST], true)) {
            return false;
        }

        $value = $this->get($keywordId = (int) $keyword->id, KeywordMetaKey::siteRescrapeKeep($siteId));
        if ($value === '1' || $value === 'true') {
            return true;
        }

        $legacyMetrics = $this->getLegacyMetricsArray($keywordId, $siteId);

        return is_array($legacyMetrics) && ($legacyMetrics[Keyword::METRIC_RESCRAPE_KEEP] ?? false) === true;
    }

    public function setRescrapeKeep(int $keywordId, int $siteId, bool $keep): void
    {
        $this->set($keywordId, KeywordMetaKey::siteRescrapeKeep($siteId), $keep ? '1' : null);
    }

    /**
     * @param  array<string, mixed>|null  $metrics
     */
    public function applySiteMetrics(int $keywordId, int $siteId, ?array $metrics): void
    {
        if ($siteId <= 0 || ! is_array($metrics)) {
            return;
        }

        if (($metrics[Keyword::METRIC_RESCRAPE_KEEP] ?? false) === true) {
            $this->setRescrapeKeep($keywordId, $siteId, true);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getLegacyMetricsArray(int $keywordId, int $siteId): ?array
    {
        $raw = $this->getSiteString($keywordId, $siteId, 'metrics');
        if ($raw === null || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Legacy global main_article_id (not site-safe). Prefer {@see getMainArticleIdForSite()}.
     */
    public function getMainArticleId(int $keywordId): ?int
    {
        $value = $this->get($keywordId, KeywordMetaKey::MainArticleId->value);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Resolve focus article for a keyword on a specific site.
     * Prefers site.{siteId}.main_article_id; falls back to legacy global only when
     * that article belongs to the same site.
     */
    public function getMainArticleIdForSite(int $keywordId, int $siteId): ?int
    {
        if ($keywordId <= 0 || $siteId <= 0) {
            return null;
        }

        $scoped = $this->get($keywordId, KeywordMetaKey::siteMainArticleId($siteId));
        if (is_numeric($scoped) && (int) $scoped > 0) {
            return (int) $scoped;
        }

        $legacy = $this->getMainArticleId($keywordId);
        if ($legacy === null) {
            return null;
        }

        $articleSiteId = DB::connection('omi_seo_ai')
            ->table('articles')
            ->where('id', $legacy)
            ->value('site_id');

        return is_numeric($articleSiteId) && (int) $articleSiteId === $siteId
            ? $legacy
            : null;
    }

    /**
     * @deprecated Prefer {@see setMainArticleIdForSite()} — global key causes cross-site contamination.
     */
    public function setMainArticleId(int $keywordId, ?int $articleId): void
    {
        $this->set(
            $keywordId,
            KeywordMetaKey::MainArticleId->value,
            $articleId !== null && $articleId > 0 ? (string) $articleId : null,
        );
    }

    /**
     * Persist site-scoped focus article. Rejects when article.site_id !== $siteId.
     *
     * @return bool false when rejected (mismatch / invalid)
     */
    public function setMainArticleIdForSite(int $keywordId, int $siteId, ?int $articleId): bool
    {
        if ($keywordId <= 0 || $siteId <= 0) {
            return false;
        }

        if ($articleId === null || $articleId <= 0) {
            $this->set($keywordId, KeywordMetaKey::siteMainArticleId($siteId), null);

            return true;
        }

        $articleSiteId = DB::connection('omi_seo_ai')
            ->table('articles')
            ->where('id', $articleId)
            ->value('site_id');

        if (! is_numeric($articleSiteId) || (int) $articleSiteId !== $siteId) {
            return false;
        }

        $this->set($keywordId, KeywordMetaKey::siteMainArticleId($siteId), (string) $articleId);

        // Keep legacy global in sync only when empty or already pointing at same-site article.
        $legacy = $this->getMainArticleId($keywordId);
        if ($legacy === null) {
            $this->setMainArticleId($keywordId, $articleId);
        } elseif ($legacy !== $articleId) {
            $legacySiteId = DB::connection('omi_seo_ai')
                ->table('articles')
                ->where('id', $legacy)
                ->value('site_id');
            if (! is_numeric($legacySiteId) || (int) $legacySiteId === $siteId) {
                $this->setMainArticleId($keywordId, $articleId);
            }
            // Else: legacy points at another site — leave it; site-scoped SoT wins for readers.
        }

        return true;
    }

    /**
     * @return list<int>
     */
    public function getTagIds(int $keywordId): array
    {
        $raw = $this->get($keywordId, KeywordMetaKey::Tags->value);
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $decoded),
            static fn (int $id): bool => $id > 0,
        ));
    }

    /**
     * @param  list<int>  $tagIds
     */
    public function setTagIds(int $keywordId, array $tagIds): void
    {
        $tagIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $tagIds),
            static fn (int $id): bool => $id > 0,
        )));

        $this->set(
            $keywordId,
            KeywordMetaKey::Tags->value,
            $tagIds !== [] ? json_encode($tagIds, JSON_THROW_ON_ERROR) : null,
        );
    }

    /**
     * @param  list<int>  $tagIds
     */
    public function mergeTagIds(int $keywordId, array $tagIds): bool
    {
        $existing = $this->getTagIds($keywordId);
        $merged = array_values(array_unique(array_merge($existing, $this->normalizeTagIds($tagIds))));

        if ($merged === $existing) {
            return false;
        }

        $this->setTagIds($keywordId, $merged);

        return true;
    }

    /**
     * @param  list<int>  $tagIds
     * @return list<int>
     */
    private function normalizeTagIds(array $tagIds): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $tagIds),
            static fn (int $id): bool => $id > 0,
        ));
    }

    /**
     * @return list<string>
     */
    public function getQualityFlags(int $keywordId): array
    {
        $raw = $this->get($keywordId, KeywordMetaKey::QualityFlags->value);
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $flag): string => trim((string) $flag), $decoded),
            static fn (string $flag): bool => in_array($flag, ['danger', 'warning'], true),
        ));
    }

    /**
     * @param  list<string>  $flags
     */
    public function setQualityFlags(int $keywordId, array $flags): void
    {
        $flags = array_values(array_unique(array_filter(
            array_map(static fn (mixed $flag): string => trim((string) $flag), $flags),
            static fn (string $flag): bool => in_array($flag, ['danger', 'warning'], true),
        )));

        $this->set(
            $keywordId,
            KeywordMetaKey::QualityFlags->value,
            $flags !== [] ? json_encode($flags, JSON_THROW_ON_ERROR) : null,
        );
    }

    public function upsertSiteBundle(
        Keyword $keyword,
        int $siteId,
        ?string $targetUrl = null,
        ?array $metrics = null,
        ?int $searchVolume = null,
        ?float $difficulty = null,
    ): void {
        $keywordId = (int) $keyword->id;
        if ($keywordId <= 0 || $siteId <= 0) {
            return;
        }

        if ($targetUrl !== null) {
            $normalized = trim($targetUrl);
            $this->setSiteTargetUrl($keywordId, $siteId, $normalized !== '' ? $normalized : null);
        }

        if ($searchVolume !== null) {
            $this->setSiteSearchVolume($keywordId, $siteId, $searchVolume);
        }

        if ($difficulty !== null) {
            $this->setSiteDifficulty($keywordId, $siteId, $difficulty);
        }

        $this->applySiteMetrics($keywordId, $siteId, $metrics);

        if (in_array($keyword->type, [Keyword::TYPE_FREE, Keyword::TYPE_SUGGEST], true)
            && ($metrics === null || ($metrics[Keyword::METRIC_RESCRAPE_KEEP] ?? false) !== true)
            && $keyword->type === Keyword::TYPE_FREE
        ) {
            $this->setRescrapeKeep($keywordId, $siteId, true);
        }
    }

    public function detachSite(int $keywordId, int $siteId): void
    {
        if ($keywordId <= 0 || $siteId <= 0) {
            return;
        }

        $this->deleteByPrefix($keywordId, "site.{$siteId}.");
    }

    public function keywordHasSiteMeta(int $keywordId, int $siteId): bool
    {
        if ($keywordId <= 0 || $siteId <= 0) {
            return false;
        }

        return KeywordMeta::query()
            ->where('keyword_id', $keywordId)
            ->where('meta_key', 'like', "site.{$siteId}.%")
            ->exists();
    }

    /**
     * @param  list<int>  $siteIds
     */
    public function keywordHasAnySiteMeta(int $keywordId, array $siteIds): bool
    {
        foreach ($siteIds as $siteId) {
            if ($this->keywordHasSiteMeta($keywordId, (int) $siteId)) {
                return true;
            }
        }

        return false;
    }

    public function deleteAllForKeyword(int $keywordId): void
    {
        if ($keywordId <= 0) {
            return;
        }

        KeywordMeta::query()->where('keyword_id', $keywordId)->delete();
    }

    /**
     * Port helper used in migration only.
     *
     * @param  list<array{keyword_id:int,meta_key:string,meta_value:?string}>  $rows
     */
    public function bulkInsertIgnore(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $now = now();
        $payload = [];
        foreach ($rows as $row) {
            $payload[] = [
                'keyword_id' => $row['keyword_id'],
                'meta_key' => $row['meta_key'],
                'meta_value' => $row['meta_value'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::connection((new KeywordMeta)->getConnectionName())
            ->table('keyword_meta')
            ->insertOrIgnore($payload);
    }
}
