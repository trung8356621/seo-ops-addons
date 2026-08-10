<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application;

use Omnichannel\Addons\SearchIntelligence\Models\SeoGscOpportunity;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscPageMapping;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscPerformanceAggregate;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscProperty;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscQueryMapping;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscSyncRun;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscSyncOperationService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use RuntimeException;

final class GscIntelligenceReadService
{
    public function __construct(
        private readonly GscSyncOperationService $syncOperations,
    ) {}

    /** @param array<string, mixed> $input */
    public function listProperties(int $siteId, array $input = []): array
    {
        $this->assertSiteAccess($siteId);
        $query = SeoGscProperty::query()->where('site_id', $siteId)->orderByDesc('id');

        if (trim((string) ($input['status'] ?? '')) !== '') {
            $query->where('status', (string) $input['status']);
        }

        $rows = $query->limit(100)->get()->map(fn (SeoGscProperty $p): array => $this->serializeProperty($p))->all();

        return ['properties' => $rows];
    }

    public function getProperty(int $siteId, string $propertyRef): array
    {
        $property = $this->resolveProperty($siteId, $propertyRef);

        return ['property' => $this->serializeProperty($property, true)];
    }

    /** @param array<string, mixed> $input */
    public function listSyncRuns(int $siteId, string $propertyRef, array $input = []): array
    {
        $property = $this->resolveProperty($siteId, $propertyRef);
        $rows = SeoGscSyncRun::query()
            ->where('property_id', $property->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (SeoGscSyncRun $r): array => $this->serializeSyncRun($r))
            ->all();

        return ['property_ref' => $property->public_ref, 'sync_runs' => $rows];
    }

    public function getSyncRun(int $siteId, string $propertyRef, string $syncRunRef): array
    {
        $property = $this->resolveProperty($siteId, $propertyRef);
        $syncRun = $this->resolveSyncRun($property, $syncRunRef);

        return ['sync_run' => $this->serializeSyncRun($syncRun, true)];
    }

    /** @param array<string, mixed> $input */
    public function listQueryMappings(int $siteId, string $propertyRef, array $input = []): array
    {
        $property = $this->resolveProperty($siteId, $propertyRef);
        $rows = SeoGscQueryMapping::query()
            ->where('property_id', $property->id)
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (SeoGscQueryMapping $m): array => $this->serializeQueryMapping($m))
            ->all();

        return ['property_ref' => $property->public_ref, 'query_mappings' => $rows];
    }

    public function getQueryMapping(int $siteId, string $propertyRef, string $mappingRef): array
    {
        $property = $this->resolveProperty($siteId, $propertyRef);
        $mapping = $this->resolveQueryMapping($property, $mappingRef);

        return ['query_mapping' => $this->serializeQueryMapping($mapping, true)];
    }

    /** @param array<string, mixed> $input */
    public function listPageMappings(int $siteId, string $propertyRef, array $input = []): array
    {
        $property = $this->resolveProperty($siteId, $propertyRef);
        $rows = SeoGscPageMapping::query()
            ->where('property_id', $property->id)
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (SeoGscPageMapping $m): array => $this->serializePageMapping($m))
            ->all();

        return ['property_ref' => $property->public_ref, 'page_mappings' => $rows];
    }

    public function getPageMapping(int $siteId, string $propertyRef, string $mappingRef): array
    {
        $property = $this->resolveProperty($siteId, $propertyRef);
        $mapping = $this->resolvePageMapping($property, $mappingRef);

        return ['page_mapping' => $this->serializePageMapping($mapping, true)];
    }

    /** @param array<string, mixed> $input */
    public function listAggregates(int $siteId, string $propertyRef, array $input = []): array
    {
        $property = $this->resolveProperty($siteId, $propertyRef);
        $rows = SeoGscPerformanceAggregate::query()
            ->where('property_id', $property->id)
            ->orderByDesc('calculated_at')
            ->limit(50)
            ->get()
            ->map(fn (SeoGscPerformanceAggregate $a): array => $this->serializeAggregate($a))
            ->all();

        return ['property_ref' => $property->public_ref, 'aggregates' => $rows];
    }

    public function getAggregate(int $siteId, string $propertyRef, string $aggregateRef): array
    {
        $property = $this->resolveProperty($siteId, $propertyRef);
        $id = KeywordIntelligencePublicRef::resolveGscPerformanceAggregateIdStrict($aggregateRef);
        $aggregate = SeoGscPerformanceAggregate::query()
            ->where('property_id', $property->id)
            ->where('id', $id)
            ->first();

        if (! $aggregate instanceof SeoGscPerformanceAggregate) {
            throw new RuntimeException('GSC aggregate không tồn tại.');
        }

        return ['aggregate' => $this->serializeAggregate($aggregate, true)];
    }

    /** @param array<string, mixed> $input */
    public function listOpportunities(int $siteId, string $propertyRef, array $input = []): array
    {
        $property = $this->resolveProperty($siteId, $propertyRef);
        $query = SeoGscOpportunity::query()->where('property_id', $property->id)->orderByDesc('priority_score');

        if (trim((string) ($input['status'] ?? '')) !== '') {
            $query->where('status', (string) $input['status']);
        }

        $rows = $query->limit(200)->get()->map(fn (SeoGscOpportunity $o): array => $this->serializeOpportunity($o))->all();

        return ['property_ref' => $property->public_ref, 'opportunities' => $rows];
    }

    public function getOpportunity(int $siteId, string $propertyRef, string $opportunityRef): array
    {
        $property = $this->resolveProperty($siteId, $propertyRef);
        $opportunity = $this->resolveOpportunity($property, $opportunityRef);

        return ['opportunity' => $this->serializeOpportunity($opportunity, true)];
    }

    public function getOperation(int $siteId, string $operationRef): array
    {
        $this->assertSiteAccess($siteId);
        $operation = $this->syncOperations->getOperation($operationRef);

        if ($operation === null) {
            throw new RuntimeException('GSC operation không tồn tại.');
        }

        return ['operation' => $operation];
    }

    private function resolveProperty(int $siteId, string $propertyRef): SeoGscProperty
    {
        $this->assertSiteAccess($siteId);
        $id = KeywordIntelligencePublicRef::resolveGscPropertyIdStrict($propertyRef);
        $property = SeoGscProperty::query()->where('site_id', $siteId)->where('id', $id)->first();

        if (! $property instanceof SeoGscProperty) {
            throw new RuntimeException('GSC property không tồn tại.');
        }

        return $property;
    }

    private function resolveSyncRun(SeoGscProperty $property, string $syncRunRef): SeoGscSyncRun
    {
        $id = KeywordIntelligencePublicRef::resolveGscSyncRunIdStrict($syncRunRef);
        $syncRun = SeoGscSyncRun::query()->where('property_id', $property->id)->where('id', $id)->first();

        if (! $syncRun instanceof SeoGscSyncRun) {
            throw new RuntimeException('GSC sync run không tồn tại.');
        }

        return $syncRun;
    }

    private function resolveQueryMapping(SeoGscProperty $property, string $mappingRef): SeoGscQueryMapping
    {
        $id = KeywordIntelligencePublicRef::resolveGscQueryMappingIdStrict($mappingRef);
        $mapping = SeoGscQueryMapping::query()->where('property_id', $property->id)->where('id', $id)->first();

        if (! $mapping instanceof SeoGscQueryMapping) {
            throw new RuntimeException('GSC query mapping không tồn tại.');
        }

        return $mapping;
    }

    private function resolvePageMapping(SeoGscProperty $property, string $mappingRef): SeoGscPageMapping
    {
        $id = KeywordIntelligencePublicRef::resolveGscPageMappingIdStrict($mappingRef);
        $mapping = SeoGscPageMapping::query()->where('property_id', $property->id)->where('id', $id)->first();

        if (! $mapping instanceof SeoGscPageMapping) {
            throw new RuntimeException('GSC page mapping không tồn tại.');
        }

        return $mapping;
    }

    private function resolveOpportunity(SeoGscProperty $property, string $opportunityRef): SeoGscOpportunity
    {
        $id = KeywordIntelligencePublicRef::resolveGscOpportunityIdStrict($opportunityRef);
        $opportunity = SeoGscOpportunity::query()->where('property_id', $property->id)->where('id', $id)->first();

        if (! $opportunity instanceof SeoGscOpportunity) {
            throw new RuntimeException('GSC opportunity không tồn tại.');
        }

        return $opportunity;
    }

    private function assertSiteAccess(int $siteId): void
    {
        if ($siteId <= 0) {
            throw new RuntimeException('site_id is required.');
        }

        if (! SeoAccessControl::canAccessSite($siteId)) {
            throw new RuntimeException('Không có quyền truy cập site.');
        }
    }

    /** @return array<string, mixed> */
    private function serializeProperty(SeoGscProperty $property, bool $detailed = false): array
    {
        $data = [
            'property_ref' => $property->public_ref,
            'display_name' => $property->display_name,
            'property_uri' => $property->property_uri,
            'provider_key' => $property->provider_key,
            'status' => $property->status?->value ?? $property->status,
            'sync_enabled' => (bool) $property->sync_enabled,
            'last_synced_at' => $property->last_synced_at?->toIso8601String(),
        ];

        if ($detailed) {
            $data['settings'] = $property->settings;
            $data['metadata'] = $property->metadata;
            $data['last_complete_date'] = $property->last_complete_date?->format('Y-m-d');
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function serializeSyncRun(SeoGscSyncRun $run, bool $detailed = false): array
    {
        $data = [
            'sync_run_ref' => $run->public_ref,
            'status' => $run->status?->value ?? $run->status,
            'date_from' => $run->date_from?->format('Y-m-d'),
            'date_to' => $run->date_to?->format('Y-m-d'),
            'received_rows' => $run->received_rows,
            'persisted_rows' => $run->persisted_rows,
        ];

        if ($detailed) {
            $data['operation_ref'] = $run->operation_ref;
            $data['error_code'] = $run->error_code;
            $data['error_message'] = $run->error_message;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function serializeQueryMapping(SeoGscQueryMapping $mapping, bool $detailed = false): array
    {
        $data = [
            'mapping_ref' => $mapping->public_ref,
            'normalized_query' => $mapping->normalized_query,
            'sample_query' => $mapping->sample_query,
            'status' => $mapping->status?->value ?? $mapping->status,
            'mapping_type' => $mapping->mapping_type?->value ?? $mapping->mapping_type,
        ];

        if ($detailed) {
            $data['keyword_id'] = $mapping->keyword_id;
            $data['cluster_id'] = $mapping->cluster_id;
            $data['metadata'] = $mapping->metadata;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function serializePageMapping(SeoGscPageMapping $mapping, bool $detailed = false): array
    {
        $data = [
            'mapping_ref' => $mapping->public_ref,
            'normalized_page' => $mapping->normalized_page,
            'article_ref' => $mapping->article_ref,
            'status' => $mapping->status?->value ?? $mapping->status,
        ];

        if ($detailed) {
            $data['metadata'] = $mapping->metadata;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function serializeAggregate(SeoGscPerformanceAggregate $aggregate, bool $detailed = false): array
    {
        $data = [
            'aggregate_ref' => $aggregate->public_ref,
            'clicks' => $aggregate->clicks,
            'impressions' => $aggregate->impressions,
            'ctr' => $aggregate->ctr,
            'position' => $aggregate->position,
        ];

        if ($detailed) {
            $data['summary'] = $aggregate->summary;
            $data['date_from'] = $aggregate->date_from?->format('Y-m-d');
            $data['date_to'] = $aggregate->date_to?->format('Y-m-d');
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function serializeOpportunity(SeoGscOpportunity $opportunity, bool $detailed = false): array
    {
        $data = [
            'opportunity_ref' => $opportunity->public_ref,
            'opportunity_type' => $opportunity->opportunity_type?->value ?? $opportunity->opportunity_type,
            'status' => $opportunity->status?->value ?? $opportunity->status,
            'priority_score' => $opportunity->priority_score,
            'recommended_action' => $opportunity->recommended_action,
        ];

        if ($detailed) {
            $data['evidence'] = $opportunity->evidence;
            $data['reason_codes'] = $opportunity->reason_codes;
        }

        return $data;
    }
}
