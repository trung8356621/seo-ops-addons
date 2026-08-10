<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscMappingStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscPageMappingType;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscQueryMappingType;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscPageMapping;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscProperty;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscQueryMapping;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Throwable;

/**
 * Persist sync/auto mapping suggestions — never overwrite manual mappings.
 */
final class GscSuggestedMappingPersistService
{
    /**
     * @param  list<array<string, mixed>>  $mappings  Sync operation mapping rows
     * @return array{query_written: int, page_written: int, query_preserved_manual: int, page_preserved_manual: int}
     */
    public function persistFromSyncResult(SeoGscProperty $property, array $mappings): array
    {
        $stats = [
            'query_written' => 0,
            'page_written' => 0,
            'query_preserved_manual' => 0,
            'page_preserved_manual' => 0,
        ];

        foreach ($mappings as $row) {
            if (! is_array($row)) {
                continue;
            }

            $pageMap = is_array($row['page_mapping'] ?? null) ? $row['page_mapping'] : [];
            $keywordMap = is_array($row['keyword_mapping'] ?? null) ? $row['keyword_mapping'] : [];

            $normalizedPage = (string) ($row['normalized_page'] ?? $pageMap['normalized_page'] ?? '');
            $normalizedQuery = (string) ($row['normalized_query'] ?? $keywordMap['normalized_query'] ?? '');

            if ($normalizedQuery !== '' && ($keywordMap['keyword_ref'] ?? null) !== null) {
                $result = $this->upsertQuerySuggestion($property, $normalizedQuery, $keywordMap);
                $stats[$result]++;
            }

            if ($normalizedPage !== '' && ($pageMap['article_ref'] ?? null) !== null) {
                $result = $this->upsertPageSuggestion($property, $normalizedPage, $pageMap);
                $stats[$result]++;
            }
        }

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $keywordMap
     * @return 'query_written'|'query_preserved_manual'
     */
    private function upsertQuerySuggestion(SeoGscProperty $property, string $normalizedQuery, array $keywordMap): string
    {
        try {
            $identityHash = hash('sha256', $property->public_ref.'|'.$normalizedQuery);
            $existing = SeoGscQueryMapping::query()
                ->where('property_id', $property->id)
                ->where('identity_hash', $identityHash)
                ->first();

            if ($existing instanceof SeoGscQueryMapping && ($existing->metadata['manual'] ?? false) === true) {
                return 'query_preserved_manual';
            }

            if (($keywordMap['preserved_manual'] ?? false) === true && $existing instanceof SeoGscQueryMapping) {
                return 'query_preserved_manual';
            }

            $mapping = $existing instanceof SeoGscQueryMapping ? $existing : new SeoGscQueryMapping([
                'public_ref' => 'pending',
                'tenant_id' => $property->tenant_id,
                'site_id' => $property->site_id,
                'property_id' => $property->id,
                'identity_hash' => $identityHash,
            ]);

            $keywordRef = (string) ($keywordMap['keyword_ref'] ?? '');
            $mapping->normalized_query = $normalizedQuery;
            $mapping->sample_query = $normalizedQuery;
            $mapping->keyword_id = $keywordRef !== ''
                ? KeywordIntelligencePublicRef::resolveKeywordIdStrict($keywordRef)
                : null;
            $matchType = (string) ($keywordMap['match_type'] ?? 'exact');
            $mapping->mapping_type = match ($matchType) {
                'near' => GscQueryMappingType::NearKeyword,
                'manual' => GscQueryMappingType::Manual,
                default => GscQueryMappingType::ExactKeyword,
            };
            $mapping->confidence = (float) ($keywordMap['confidence'] ?? 0.0);
            $mapping->source = 'sync_auto';
            $mapping->status = GscMappingStatus::Candidate;
            $mapping->metadata = array_merge((array) ($mapping->metadata ?? []), [
                'manual' => false,
                'match_type' => $keywordMap['match_type'] ?? null,
            ]);
            $mapping->save();

            if ($mapping->public_ref === 'pending') {
                $mapping->public_ref = KeywordIntelligencePublicRef::gscQueryMapping((int) $mapping->id);
                $mapping->save();
            }

            return 'query_written';
        } catch (Throwable) {
            return 'query_written';
        }
    }

    /**
     * @param  array<string, mixed>  $pageMap
     * @return 'page_written'|'page_preserved_manual'
     */
    private function upsertPageSuggestion(SeoGscProperty $property, string $normalizedPage, array $pageMap): string
    {
        try {
            $identityHash = hash('sha256', $property->public_ref.'|'.$normalizedPage);
            $existing = SeoGscPageMapping::query()
                ->where('property_id', $property->id)
                ->where('identity_hash', $identityHash)
                ->first();

            if ($existing instanceof SeoGscPageMapping && ($existing->metadata['manual'] ?? false) === true) {
                return 'page_preserved_manual';
            }

            $mapping = $existing instanceof SeoGscPageMapping ? $existing : new SeoGscPageMapping([
                'public_ref' => 'pending',
                'tenant_id' => $property->tenant_id,
                'site_id' => $property->site_id,
                'property_id' => $property->id,
                'identity_hash' => $identityHash,
            ]);

            $mapping->page = $normalizedPage;
            $mapping->normalized_page = $normalizedPage;
            $mapping->article_ref = (string) ($pageMap['article_ref'] ?? '');
            $methodRaw = $pageMap['method'] ?? $pageMap['match_type'] ?? 'exact_canonical';
            $method = $methodRaw instanceof \BackedEnum ? $methodRaw->value : (string) $methodRaw;
            $mapping->mapping_type = match ($method) {
                'exact_wp_url', 'exact_wp' => GscPageMappingType::ExactWpUrl,
                'slug_match', 'slug' => GscPageMappingType::SlugMatch,
                'redirect_match', 'redirect' => GscPageMappingType::RedirectMatch,
                'manual' => GscPageMappingType::Manual,
                default => GscPageMappingType::ExactCanonicalUrl,
            };
            $mapping->confidence = (float) ($pageMap['confidence'] ?? 0.0);
            $mapping->source = 'sync_auto';
            $mapping->status = GscMappingStatus::Candidate;
            $mapping->metadata = array_merge((array) ($mapping->metadata ?? []), [
                'manual' => false,
                'method' => $method,
            ]);
            $mapping->save();

            if ($mapping->public_ref === 'pending') {
                $mapping->public_ref = KeywordIntelligencePublicRef::gscPageMapping((int) $mapping->id);
                $mapping->save();
            }

            return 'page_written';
        } catch (Throwable) {
            return 'page_written';
        }
    }
}
