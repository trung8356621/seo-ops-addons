<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpFeatureType;
use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpResultType;
use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpSnapshotStatus;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpFeature;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpQuery;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpResult;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpSnapshot;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Data\SerpProviderResult;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * Tạo pending snapshot, persist normalized results/features, hoàn tất immutability.
 */
final class SerpSnapshotPersistService
{
    public function __construct(
        private readonly SerpResultClassifier $resultClassifier,
        private readonly SerpUrlNormalizationService $urlNormalizer,
    ) {}

    public function createPending(SeoSerpQuery $query, string $providerKey): SeoSerpSnapshot
    {
        $snapshot = new SeoSerpSnapshot([
            'public_ref' => 'pending',
            'tenant_id' => $query->tenant_id,
            'site_id' => $query->site_id,
            'serp_query_id' => $query->id,
            'provider_key' => $providerKey,
            'captured_at' => now(),
            'status' => SerpSnapshotStatus::Pending,
            'device' => $query->device,
            'search_engine' => $query->search_engine,
            'locale' => trim(($query->language ?? '').'-'.($query->country ?? ''), '-'),
            'location' => $query->location,
        ]);
        $snapshot->save();
        $snapshot->public_ref = KeywordIntelligencePublicRef::serpSnapshot((int) $snapshot->id);
        $snapshot->save();

        return $snapshot->fresh() ?? $snapshot;
    }

    public function persistFromProviderResult(SeoSerpSnapshot $snapshot, SerpProviderResult $providerResult): SeoSerpSnapshot
    {
        if ($snapshot->status?->isImmutable()) {
            throw new LogicException('SERP snapshot is immutable after completion.');
        }

        if (! $providerResult->success) {
            $snapshot->status = SerpSnapshotStatus::Failed;
            $snapshot->error_code = $providerResult->errorCode;
            $snapshot->error_message = $providerResult->errorMessage;
            $snapshot->completed_at = now();
            $snapshot->save();

            return $snapshot->fresh() ?? $snapshot;
        }

        return DB::connection('omi_seo_ai')->transaction(function () use ($snapshot, $providerResult): SeoSerpSnapshot {
            $snapshot->status = SerpSnapshotStatus::Normalizing;
            $snapshot->save();

            $organicCount = 0;
            $resultRefs = [];

            foreach ($providerResult->results as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $classified = $this->resultClassifier->classify($row);
                $url = trim((string) ($row['url'] ?? $row['link'] ?? ''));
                $normalized = $this->urlNormalizer->normalize($url);

                $result = new SeoSerpResult([
                    'public_ref' => 'pending',
                    'snapshot_id' => $snapshot->id,
                    'tenant_id' => $snapshot->tenant_id,
                    'site_id' => $snapshot->site_id,
                    'position' => (int) ($row['position'] ?? ($index + 1)),
                    'result_type' => $classified['type'] ?? SerpResultType::Organic,
                    'url' => $url,
                    'normalized_url' => $normalized['normalized_url'],
                    'domain' => $normalized['domain'],
                    'normalized_domain' => $normalized['normalized_domain'],
                    'title' => isset($row['title']) ? (string) $row['title'] : null,
                    'snippet' => isset($row['snippet']) ? (string) $row['snippet'] : null,
                    'page_type' => $row['page_type'] ?? null,
                    'search_intent' => $row['search_intent'] ?? null,
                    'metadata' => is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
                ]);
                $result->save();
                $result->public_ref = KeywordIntelligencePublicRef::serpResult((int) $result->id);
                $result->save();

                $resultRefs[] = $result->public_ref;
                if (($classified['type'] ?? SerpResultType::Organic) === SerpResultType::Organic) {
                    $organicCount++;
                }
            }

            $featureCount = 0;
            foreach ($providerResult->features as $index => $feature) {
                if (! is_array($feature)) {
                    continue;
                }

                $typeRaw = (string) ($feature['type'] ?? $feature['feature_type'] ?? 'other');
                $featureModel = new SeoSerpFeature([
                    'public_ref' => 'pending',
                    'snapshot_id' => $snapshot->id,
                    'tenant_id' => $snapshot->tenant_id,
                    'site_id' => $snapshot->site_id,
                    'feature_type' => SerpFeatureType::tryFrom($typeRaw) ?? SerpFeatureType::Other,
                    'position' => isset($feature['position']) ? (int) $feature['position'] : ($index + 1),
                    'title' => isset($feature['title']) ? (string) $feature['title'] : null,
                    'text' => isset($feature['text']) ? (string) $feature['text'] : null,
                    'source_url' => isset($feature['source_url']) ? (string) $feature['source_url'] : null,
                    'question' => isset($feature['question']) ? (string) $feature['question'] : null,
                    'metadata' => is_array($feature['metadata'] ?? null) ? $feature['metadata'] : [],
                ]);
                $featureModel->save();
                $featureModel->public_ref = KeywordIntelligencePublicRef::serpFeature((int) $featureModel->id);
                $featureModel->save();
                $featureCount++;
            }

            $checksum = $this->computeChecksum($providerResult);
            $snapshot->result_count = count($resultRefs);
            $snapshot->organic_result_count = $organicCount;
            $snapshot->feature_count = $featureCount;
            $snapshot->normalized_checksum = $checksum;
            $snapshot->summary = [
                'result_refs' => $resultRefs,
                'provider_metadata' => $providerResult->metadata,
            ];
            $snapshot->status = SerpSnapshotStatus::Completed;
            $snapshot->completed_at = now();
            $snapshot->save();

            $query = SeoSerpQuery::query()->find($snapshot->serp_query_id);
            if ($query instanceof SeoSerpQuery) {
                $query->latest_snapshot_ref = $snapshot->public_ref;
                $query->save();
            }

            return $snapshot->fresh() ?? $snapshot;
        });
    }

    public function assertMutable(SeoSerpSnapshot $snapshot): void
    {
        if ($snapshot->status?->isImmutable()) {
            throw new RuntimeException('serp.snapshot_immutable');
        }
    }

    private function computeChecksum(SerpProviderResult $providerResult): string
    {
        $payload = json_encode([
            'results' => $providerResult->results,
            'features' => $providerResult->features,
        ], JSON_THROW_ON_ERROR);

        return hash('xxh3', $payload);
    }
}
