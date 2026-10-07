<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordWorkspaceMetric;

final class KeywordWorkspaceMetricCache
{
    public const DICTIONARY = 'dictionary';
    public const FOCUS = 'focus';
    public const TOPICS = 'topics';
    public const GROUPS = 'groups';
    public const TAGS = 'tags';
    public const EXTERNAL = 'external';

    private const SAFETY_TTL_HOURS = 24;

    /**
     * @param  list<string>  $metricKeys
     * @param  callable(): array<string, int>  $recompute
     * @return array<string, int>
     */
    public function rememberMetrics(
        int $siteId,
        ?string $languageCode,
        string $namespace,
        array $metricKeys,
        callable $recompute,
    ): array {
        if ($siteId <= 0 || ! $this->ready()) {
            return $recompute();
        }

        $languageKey = $this->languageKey($languageCode);
        $rows = KeywordWorkspaceMetric::query()
            ->where('site_id', $siteId)
            ->where('language_code', $languageKey)
            ->where('namespace', $namespace)
            ->whereIn('metric_key', $metricKeys)
            ->get()
            ->keyBy('metric_key');
        $now = now();
        $fresh = [];
        foreach ($metricKeys as $metricKey) {
            $row = $rows->get($metricKey);
            if (! $row instanceof KeywordWorkspaceMetric
                || ($row->expires_at !== null && $row->expires_at->lte($now))) {
                $fresh = [];
                break;
            }
            $fresh[$metricKey] = (int) $row->value;
        }
        if (count($fresh) === count($metricKeys)) {
            return $fresh;
        }

        $computed = $recompute();
        $expiresAt = $now->copy()->addHours(self::SAFETY_TTL_HOURS);
        foreach ($metricKeys as $metricKey) {
            KeywordWorkspaceMetric::query()->updateOrCreate(
                [
                    'site_id' => $siteId,
                    'language_code' => $languageKey,
                    'namespace' => $namespace,
                    'metric_key' => $metricKey,
                ],
                [
                    'value' => (int) ($computed[$metricKey] ?? 0),
                    'generated_at' => $now,
                    'expires_at' => $expiresAt,
                ],
            );
        }

        $result = [];
        foreach ($metricKeys as $metricKey) {
            $result[$metricKey] = (int) ($computed[$metricKey] ?? 0);
        }

        return $result;
    }

    public function invalidateNamespace(int $siteId, string $namespace, ?string $languageCode = null): void
    {
        if ($siteId <= 0 || ! $this->ready()) {
            return;
        }
        KeywordWorkspaceMetric::query()
            ->where('site_id', $siteId)
            ->where('namespace', $namespace)
            ->when($languageCode !== null, fn ($query) => $query->where('language_code', $this->languageKey($languageCode)))
            ->delete();
    }

    public function invalidateMetric(
        int $siteId,
        string $namespace,
        string $metricKey,
        ?string $languageCode = null,
    ): void {
        if ($siteId <= 0 || ! $this->ready()) {
            return;
        }
        KeywordWorkspaceMetric::query()
            ->where('site_id', $siteId)
            ->where('namespace', $namespace)
            ->where('metric_key', $metricKey)
            ->when($languageCode !== null, fn ($query) => $query->where('language_code', $this->languageKey($languageCode)))
            ->delete();
    }

    public function ready(): bool
    {
        return Schema::connection('omi_seo_ai')->hasTable('seo_keyword_workspace_metrics');
    }

    private function languageKey(?string $languageCode): string
    {
        $languageCode = strtolower(trim((string) $languageCode));

        return $languageCode !== '' ? $languageCode : '*';
    }
}
