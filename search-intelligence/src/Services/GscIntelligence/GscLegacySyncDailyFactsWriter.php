<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscPropertyStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscPropertyType;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscSearchType;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscProperty;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/** Persist legacy GSC API sync rows into seo_gsc_daily_metrics. */
final class GscLegacySyncDailyFactsWriter
{
    public function __construct(
        private readonly GscDailyMetricPersistService $persistService,
    ) {}

    public function ensureProperty(int $siteId, string $propertyUrl, ?int $legacyMappingId = null): ?SeoGscProperty
    {
        if ($siteId <= 0 || trim($propertyUrl) === '') {
            return null;
        }

        try {
            if (! Schema::connection('omi_seo_ai')->hasTable('seo_gsc_properties')) {
                return null;
            }

            $existing = SeoGscProperty::query()
                ->where('site_id', $siteId)
                ->whereNull('archived_at')
                ->where('status', '!=', GscPropertyStatus::Archived->value)
                ->orderByDesc('id')
                ->first();

            if ($existing instanceof SeoGscProperty) {
                if ($legacyMappingId !== null && $existing->legacy_mapping_id === null) {
                    $existing->legacy_mapping_id = $legacyMappingId;
                    $existing->save();
                }

                return $existing;
            }

            $propertyUri = trim($propertyUrl);
            $identityHash = hash('sha256', $siteId.'|'.$propertyUri.'|google_search_console');
            $property = new SeoGscProperty([
                'public_ref' => 'pending',
                'site_id' => $siteId,
                'provider_key' => 'google_search_console',
                'property_uri' => $propertyUri,
                'identity_hash' => $identityHash,
                'property_type' => GscPropertyType::Domain,
                'display_name' => $propertyUri,
                'status' => GscPropertyStatus::Active,
                'sync_enabled' => true,
                'default_search_type' => GscSearchType::Web,
                'legacy_mapping_id' => $legacyMappingId,
            ]);
            $property->save();
            $property->public_ref = KeywordIntelligencePublicRef::gscProperty((int) $property->id);
            $property->save();

            return $property;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $apiRows  GSC API rows with keys [date, query]
     */
    public function persistDateQueryRows(SeoGscProperty $property, array $apiRows, string $syncRef): int
    {
        $normalizedRows = [];
        foreach ($apiRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $keys = $row['keys'] ?? [];
            if (! is_array($keys) || count($keys) < 2) {
                continue;
            }

            $date = trim((string) ($keys[0] ?? ''));
            $query = trim((string) ($keys[1] ?? ''));
            if ($date === '' || $query === '') {
                continue;
            }

            $clicks = (int) ($row['clicks'] ?? 0);
            $impressions = (int) ($row['impressions'] ?? 0);
            $normalizedQuery = Str::lower($query);

            $normalizedRows[] = [
                'date' => $date,
                'query' => $query,
                'normalized_query' => $normalizedQuery,
                'page' => '',
                'normalized_page' => '',
                'clicks' => $clicks,
                'impressions' => $impressions,
                'ctr' => (float) ($row['ctr'] ?? ($impressions > 0 ? $clicks / $impressions : 0.0)),
                'position' => isset($row['position']) ? round((float) $row['position'], 4) : null,
            ];
        }

        if ($normalizedRows === []) {
            return 0;
        }

        $context = [
            'property_id' => (int) $property->id,
            'site_id' => (int) $property->site_id,
            'search_type' => 'web',
            'source' => 'gsc_api',
            'source_ref' => $syncRef,
        ];

        $result = $this->persistService->upsertMany((string) $property->public_ref, $normalizedRows, $context);
        $property->last_synced_at = now();
        $property->save();

        return count($result['rows'] ?? []);
    }
}
