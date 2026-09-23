<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\V3;

use App\Models\Site;
use Omnichannel\Addons\Content\Support\ArticleLanguageCode;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncV3Schema;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;

/**
 * Language-safe V3 checkpoint storage.
 *
 * Unscoped / single-language sites keep legacy flat meta keys.
 * Multilingual sites store per-language entries under META_LANGUAGE_CHECKPOINTS
 * and mirror primary into legacy keys for backward compatibility.
 */
final class SiteSyncV3CheckpointStore
{
    /**
     * @return array{baseline_completed_at?: string, baseline_generation?: int, delta_checkpoint_at?: string, site_revision?: string}|null
     */
    public function get(Site $site, string $languageScope): ?array
    {
        $languageScope = ArticleLanguageCode::normalize($languageScope);
        $map = $this->all($site);

        if ($languageScope !== '' && isset($map[$languageScope]) && is_array($map[$languageScope])) {
            return $map[$languageScope];
        }

        // Legacy flat keys (single-language / pre-scoped sites).
        if ($languageScope === '' || $map === []) {
            $at = trim((string) ($site->getMeta(SiteSyncV3Schema::META_BASELINE_COMPLETED_AT) ?? ''));
            $delta = trim((string) ($site->getMeta(SiteSyncV3Schema::META_DELTA_CHECKPOINT_AT) ?? ''));
            $gen = (int) ($site->getMeta(SiteSyncV3Schema::META_BASELINE_GENERATION) ?? 0);
            if ($at === '' && $delta === '' && $gen <= 0) {
                return null;
            }

            return array_filter([
                'baseline_completed_at' => $at !== '' ? $at : null,
                'baseline_generation' => $gen > 0 ? $gen : null,
                'delta_checkpoint_at' => $delta !== '' ? $delta : null,
            ], static fn (mixed $v): bool => $v !== null);
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(Site $site): array
    {
        $raw = $site->getMeta(SiteSyncV3Schema::META_LANGUAGE_CHECKPOINTS);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $this->normalizeMap($decoded);
            }
        }
        if (is_array($raw)) {
            return $this->normalizeMap($raw);
        }

        return [];
    }

    public function hasSuccessfulBaseline(Site $site, string $languageScope): bool
    {
        $row = $this->get($site, $languageScope);
        $at = trim((string) ($row['baseline_completed_at'] ?? ''));

        return $at !== '';
    }

    public function resolveDeltaCheckpoint(Site $site, string $languageScope): ?string
    {
        $row = $this->get($site, $languageScope);
        $explicit = trim((string) ($row['delta_checkpoint_at'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $baseline = trim((string) ($row['baseline_completed_at'] ?? ''));
        if ($baseline !== '') {
            return $baseline;
        }

        // Fallback: latest successful scoped run meta.
        $query = SeoSiteSyncRun::query()
            ->where('site_id', (int) $site->id)
            ->where('protocol_version', (string) SiteSyncV3Schema::PROTOCOL)
            ->whereIn('status', ['completed', 'completed_with_warnings'])
            ->orderByDesc('id');

        foreach ($query->limit(20)->get() as $run) {
            $meta = is_array($run->meta) ? $run->meta : [];
            $runLang = ArticleLanguageCode::normalize((string) ($meta[SiteSyncV3Schema::META_LANGUAGE_SCOPE] ?? ''));
            if ($languageScope !== '' && $runLang !== '' && $runLang !== $languageScope) {
                continue;
            }
            $fromRun = trim((string) ($meta['v3_delta_checkpoint_at'] ?? $meta[SiteSyncV3Schema::META_IMPORT_SINCE] ?? ''));
            if ($fromRun !== '') {
                return $fromRun;
            }
        }

        return null;
    }

    /**
     * @param  array{baseline_completed_at?: string, baseline_generation?: int|string, delta_checkpoint_at?: string, site_revision?: string|null}  $patch
     */
    public function put(Site $site, string $languageScope, array $patch, bool $isPrimary = true): void
    {
        $languageScope = ArticleLanguageCode::normalize($languageScope);
        $map = $this->all($site);
        $key = $languageScope !== '' ? $languageScope : '_default';
        $existing = is_array($map[$key] ?? null) ? $map[$key] : [];
        $merged = array_merge($existing, array_filter($patch, static fn (mixed $v): bool => $v !== null && $v !== ''));
        $map[$key] = $merged;
        SiteSyncSiteMeta::putJson($site, SiteSyncV3Schema::META_LANGUAGE_CHECKPOINTS, $map);

        // Mirror primary / unscoped into legacy flat keys for older readers.
        if ($isPrimary || $languageScope === '') {
            if (isset($merged['baseline_completed_at'])) {
                SiteSyncSiteMeta::put(
                    $site,
                    SiteSyncV3Schema::META_BASELINE_COMPLETED_AT,
                    (string) $merged['baseline_completed_at'],
                );
            }
            if (isset($merged['baseline_generation'])) {
                SiteSyncSiteMeta::put(
                    $site,
                    SiteSyncV3Schema::META_BASELINE_GENERATION,
                    (string) $merged['baseline_generation'],
                );
            }
            if (isset($merged['delta_checkpoint_at'])) {
                SiteSyncSiteMeta::put(
                    $site,
                    SiteSyncV3Schema::META_DELTA_CHECKPOINT_AT,
                    (string) $merged['delta_checkpoint_at'],
                );
            }
        }
    }

    /**
     * @param  array<mixed>  $raw
     * @return array<string, array<string, mixed>>
     */
    private function normalizeMap(array $raw): array
    {
        $out = [];
        foreach ($raw as $lang => $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = ArticleLanguageCode::normalize((string) $lang);
            if ($key === '') {
                $key = '_default';
            }
            $out[$key] = $row;
        }

        return $out;
    }
}
