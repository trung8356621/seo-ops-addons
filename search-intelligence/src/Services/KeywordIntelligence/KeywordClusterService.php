<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordClusterStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordClusterType;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordFunnelStage;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordSearchIntent;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordCluster;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKiKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterCandidate;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Phase 2 clustering: intent + entity/modifier buckets (KeywordCandidateBucketer) —
 * not cosine-only. Strategies: strict | balanced | broad.
 *
 * Bảo vệ dữ liệu đã review:
 * - Cluster status approved|converted KHÔNG bị mutate lại thành viên.
 * - Keyword field_sources['cluster_id'] === 'manual' KHÔNG bị bucket lại.
 * - recluster_draft_only: chỉ recluster keyword đang ở cluster draft (hoặc chưa có cluster).
 */
final class KeywordClusterService
{
    private const DEFAULT_MAX_KEYWORDS_PER_CLUSTER = 40;

    /** @var list<KeywordClusterStatus> */
    private const ALWAYS_PROTECTED_STATUSES = [KeywordClusterStatus::Approved, KeywordClusterStatus::Converted];

    public function __construct(
        private readonly ClusterPrimaryKeywordSelector $primarySelector,
        private readonly KeywordClusterValidator $validator,
        private readonly KeywordCandidateBucketer $bucketer,
        private readonly KeywordManualOverrideGuard $overrideGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return list<SeoKeywordCluster>
     */
    public function clusterWorkspace(SeoKeywordWorkspace $workspace, string $strategy = 'balanced', array $options = []): array
    {
        $strategy = in_array($strategy, ['strict', 'balanced', 'broad'], true) ? $strategy : 'balanced';
        $reclusterDraftOnly = (bool) ($options['recluster_draft_only'] ?? false);
        $preserveManual = (bool) ($options['preserve_manual_overrides'] ?? true);
        $maxPerCluster = max(5, $this->configInt('seo-content-ai.keyword_intelligence.clustering.max_cluster_size', self::DEFAULT_MAX_KEYWORDS_PER_CLUSTER));

        $keywords = SeoKiKeyword::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_excluded', false)
            ->where('is_duplicate', false)
            ->orderBy('id')
            ->get();

        if ($keywords->isEmpty()) {
            return [];
        }

        $clusterStatusById = SeoKeywordCluster::query()
            ->where('workspace_id', $workspace->id)
            ->pluck('status', 'id');

        [$eligible, $protectedClusterIds] = $this->partitionEligible(
            $keywords,
            $clusterStatusById,
            $reclusterDraftOnly,
            $preserveManual,
        );

        $this->annotateProtection($workspace, $protectedClusterIds, $keywords, $clusterStatusById);

        if ($eligible->isEmpty()) {
            return [];
        }

        $bucketed = $this->bucketer->bucket($eligible, $strategy);

        $created = [];
        foreach ($bucketed['buckets'] as $bucketKeywords) {
            if ($bucketKeywords === []) {
                continue;
            }

            foreach (array_chunk($bucketKeywords, $maxPerCluster) as $chunk) {
                $created = array_merge($created, $this->tryBuildCluster($workspace, $chunk, $strategy));
            }
        }

        $workspace->cluster_count = SeoKeywordCluster::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', '!=', KeywordClusterStatus::Excluded->value)
            ->count();
        $workspace->save();

        return $created;
    }

    /**
     * @param  Collection<int, SeoKiKeyword>  $keywords
     * @param  Collection<int, string>  $clusterStatusById
     * @return array{0: Collection<int, SeoKiKeyword>, 1: list<int>}
     */
    private function partitionEligible(
        Collection $keywords,
        Collection $clusterStatusById,
        bool $reclusterDraftOnly,
        bool $preserveManual,
    ): array {
        $protectedClusterIds = [];

        $eligible = $keywords->reject(function (SeoKiKeyword $keyword) use ($clusterStatusById, $reclusterDraftOnly, $preserveManual, &$protectedClusterIds): bool {
            if ($preserveManual && $this->overrideGuard->isManual($keyword, 'cluster_id')) {
                if ($keyword->cluster_id !== null) {
                    $protectedClusterIds[] = (int) $keyword->cluster_id;
                }

                return true;
            }

            $clusterId = $keyword->cluster_id;
            if ($clusterId === null) {
                return false;
            }

            $status = (string) ($clusterStatusById->get($clusterId) ?? '');

            if (in_array($status, array_map(static fn (KeywordClusterStatus $s): string => $s->value, self::ALWAYS_PROTECTED_STATUSES), true)) {
                $protectedClusterIds[] = (int) $clusterId;

                return true;
            }

            if ($reclusterDraftOnly && $status !== KeywordClusterStatus::Draft->value) {
                $protectedClusterIds[] = (int) $clusterId;

                return true;
            }

            return false;
        })->values();

        return [$eligible, array_values(array_unique($protectedClusterIds))];
    }

    /**
     * Không mutate cluster đã approved/converted — chỉ ghi chú vào metadata rằng cluster
     * này bị bỏ qua ở lần recluster này (và lý do), để UI review có thể hiển thị gợi ý.
     *
     * @param  list<int>  $protectedClusterIds
     * @param  Collection<int, SeoKiKeyword>  $keywords
     * @param  Collection<int, string>  $clusterStatusById
     */
    private function annotateProtection(
        SeoKeywordWorkspace $workspace,
        array $protectedClusterIds,
        Collection $keywords,
        Collection $clusterStatusById,
    ): void {
        if ($protectedClusterIds === []) {
            return;
        }

        $lockedCountByCluster = [];
        foreach ($keywords as $keyword) {
            $clusterId = $keyword->cluster_id;
            if ($clusterId !== null && in_array((int) $clusterId, $protectedClusterIds, true)) {
                $lockedCountByCluster[(int) $clusterId] = ($lockedCountByCluster[(int) $clusterId] ?? 0) + 1;
            }
        }

        if ($lockedCountByCluster === []) {
            return;
        }

        try {
            SeoKeywordCluster::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('id', array_keys($lockedCountByCluster))
                ->get()
                ->each(function (SeoKeywordCluster $cluster) use ($lockedCountByCluster, $clusterStatusById): void {
                    $meta = (array) ($cluster->metadata ?? []);
                    $meta['protection'] = [
                        'locked_keyword_count' => $lockedCountByCluster[(int) $cluster->id] ?? 0,
                        'reason' => (string) ($clusterStatusById->get($cluster->id) ?? ''),
                        'checked_at' => now()->toIso8601String(),
                    ];
                    $cluster->metadata = $meta;
                    $cluster->save();
                });
        } catch (Throwable) {
            // best-effort annotation — không chặn pipeline.
        }
    }

    /**
     * @param  list<SeoKiKeyword>  $chunk
     * @return list<SeoKeywordCluster>
     */
    private function tryBuildCluster(SeoKeywordWorkspace $workspace, array $chunk, string $strategy, int $depth = 0): array
    {
        if ($chunk === []) {
            return [];
        }

        $primary = $this->primarySelector->select($chunk);
        $intent = $primary->search_intent instanceof KeywordSearchIntent ? $primary->search_intent : null;
        $funnelStage = $primary->funnel_stage instanceof KeywordFunnelStage ? $primary->funnel_stage : null;

        [$entity, $modifiers] = $this->deriveEntityModifiers($primary);
        $clusterType = $this->inferClusterType($intent);
        $suggestedContentType = $this->inferSuggestedContentType($clusterType, $intent);
        $suggestedName = trim((string) $primary->keyword) !== '' ? (string) $primary->keyword : $entity;

        $candidate = new KeywordClusterCandidate(
            candidateRef: 'preview-'.$primary->id,
            keywordIds: array_map(static fn (SeoKiKeyword $k): int => (int) $k->id, $chunk),
            primaryKeywordId: (int) $primary->id,
            intent: $intent,
            funnelStage: $funnelStage,
            entity: $entity,
            modifiers: $modifiers,
            suggestedName: $suggestedName,
            suggestedContentType: $suggestedContentType,
            confidence: 0.7,
        );

        $validation = $this->validator->validate($candidate);

        if ($validation['status'] === 'invalid') {
            // keyword.cluster_empty / primary_not_in_members / missing_name — chỉ xảy ra ở
            // biên dữ liệu bất thường. Giữ nguyên các keyword này ở trạng thái chưa cluster.
            return [];
        }

        if ($validation['status'] === 'needs_split' && $depth < 3 && count($chunk) > 1) {
            $maxSize = max(1, (int) (count($chunk) / 2));
            $created = [];
            foreach (array_chunk($chunk, $maxSize) as $subChunk) {
                $created = array_merge($created, $this->tryBuildCluster($workspace, $subChunk, $strategy, $depth + 1));
            }

            return $created;
        }

        return [$this->persistCluster($workspace, $chunk, $primary, $intent, $funnelStage, $clusterType, $suggestedContentType, $strategy, $validation)];
    }

    /**
     * @param  list<SeoKiKeyword>  $chunk
     * @param  array{status: string, reasons: list<string>}  $validation
     */
    private function persistCluster(
        SeoKeywordWorkspace $workspace,
        array $chunk,
        SeoKiKeyword $primary,
        ?KeywordSearchIntent $intent,
        ?KeywordFunnelStage $funnelStage,
        KeywordClusterType $clusterType,
        string $suggestedContentType,
        string $strategy,
        array $validation,
    ): SeoKeywordCluster {
        $name = (string) $primary->keyword;
        $slug = Str::slug(mb_substr($name, 0, 80));
        if ($slug === '') {
            $slug = 'cluster-'.$primary->id;
        }

        $cluster = new SeoKeywordCluster([
            'public_ref' => 'pending',
            'workspace_id' => $workspace->id,
            'tenant_id' => $workspace->tenant_id,
            'site_id' => $workspace->site_id,
            'name' => $name,
            'slug' => $slug.'-'.$primary->id,
            'primary_keyword_id' => $primary->id,
            'search_intent' => $intent?->value,
            'funnel_stage' => $funnelStage?->value,
            'cluster_type' => $clusterType->value,
            'status' => KeywordClusterStatus::Draft->value,
            'keyword_count' => count($chunk),
            'relevance_score' => $primary->relevance_score,
            'opportunity_score' => $primary->opportunity_score,
            'priority_score' => $primary->priority_score ?? $primary->total_score,
            'suggested_content_type' => $suggestedContentType,
            'suggested_title' => $primary->keyword,
            'suggested_description' => null,
            'metadata' => [
                'strategy' => $strategy,
                'validation' => $validation,
            ],
        ]);
        $cluster->save();
        $cluster->public_ref = KeywordIntelligencePublicRef::cluster((int) $cluster->id);
        $cluster->save();

        foreach ($chunk as $member) {
            $member->cluster_id = (int) $cluster->id;
            $member->is_primary = (int) $member->id === (int) $primary->id;
            $sources = (array) ($member->field_sources ?? []);
            if (! $this->overrideGuard->isManual($member, 'cluster_id')) {
                $sources['cluster_id'] = 'auto';
            }
            $member->field_sources = $sources;
            $member->save();
        }

        return $cluster;
    }

    /**
     * @param  SeoKiKeyword  $primary
     * @return array{0: string, 1: list<string>}
     */
    private function deriveEntityModifiers(SeoKiKeyword $primary): array
    {
        $normalized = trim((string) $primary->normalized_keyword);
        $tokens = preg_split('/\s+/u', $normalized) ?: [];
        $entity = $tokens[0] ?? $normalized;
        $modifiers = array_slice($tokens, 1);

        return [$entity, $modifiers];
    }

    private function inferClusterType(?KeywordSearchIntent $intent): KeywordClusterType
    {
        return match ($intent) {
            KeywordSearchIntent::Transactional => KeywordClusterType::Transactional,
            KeywordSearchIntent::Commercial => KeywordClusterType::Commercial,
            KeywordSearchIntent::Local => KeywordClusterType::Local,
            KeywordSearchIntent::Informational => KeywordClusterType::Supporting,
            default => KeywordClusterType::Cluster,
        };
    }

    /**
     * SEO page types cho suggestion — không dùng "write_new" (không phải content type).
     */
    private function inferSuggestedContentType(KeywordClusterType $clusterType, ?KeywordSearchIntent $intent): string
    {
        return match (true) {
            $clusterType === KeywordClusterType::Local => 'local_landing',
            $clusterType === KeywordClusterType::Faq => 'faq',
            $clusterType === KeywordClusterType::Comparison => 'comparison',
            $clusterType === KeywordClusterType::Commercial => 'comparison',
            $clusterType === KeywordClusterType::Transactional && $intent === KeywordSearchIntent::Transactional => 'product',
            $clusterType === KeywordClusterType::Transactional => 'landing_page',
            $clusterType === KeywordClusterType::Pillar => 'category',
            $clusterType === KeywordClusterType::Supporting => 'article',
            $clusterType === KeywordClusterType::Cluster => 'article',
            default => 'unknown',
        };
    }

    private function configInt(string $key, int $default): int
    {
        if (! function_exists('config')) {
            return $default;
        }

        try {
            $value = (int) config($key, $default);

            return $value > 0 ? $value : $default;
        } catch (Throwable) {
            return $default;
        }
    }
}
