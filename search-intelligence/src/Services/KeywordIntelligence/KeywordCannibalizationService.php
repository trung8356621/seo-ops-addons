<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordArticleMappingType;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordCannibalizationIssueStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordCannibalizationIssueType;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordCannibalizationRecommendedAction;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordCannibalizationRiskLevel;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordArticleMapping;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordCannibalizationIssue;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordCluster;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKiKeyword;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Phase 2 — phát hiện + PERSIST rủi ro cannibalization vào seo_keyword_cannibalization_issues.
 *
 * C1 same_keyword_multi_article    — 1 keyword trỏ current_content tới >=N bài viết khác nhau.
 * C2 cluster_multi_article         — 1 cluster có keyword trỏ current_content tới >=N bài khác nhau.
 * C3 multi_cluster_same_article    — 1 bài viết nhận PRIMARY keyword mapping từ >=2 cluster khác nhau.
 * C4 planned_vs_existing           — cùng keyword vừa có planned_target vừa có current_content.
 * C5 near_primary_conflict         — primary keyword của 2 cluster khác nhau bị near-duplicate.
 * C6 manual_mapping_conflict       — nhiều mapping thủ công (is_manual) mâu thuẫn nhau trên 1 keyword.
 *
 * Không false-positive: nhiều keyword hợp lệ cùng trỏ 1 bài viết (secondary/supporting keywords)
 * KHÔNG phải cannibalization — chỉ ngưỡng theo SỐ BÀI VIẾT khác nhau (C1/C2) hoặc theo PRIMARY
 * keyword của nhiều cluster khác nhau (C3), không theo tổng số keyword trỏ vào 1 bài.
 */
final class KeywordCannibalizationService
{
    /**
     * @param  array<string, mixed>  $options
     * @return list<array<string, mixed>>
     */
    public function detect(SeoKeywordWorkspace $workspace, array $options = []): array
    {
        $threshold = max(2, (int) $this->config('seo-content-ai.keyword_intelligence.cannibalization.multi_mapping_threshold', 2));

        $drafts = array_merge(
            $this->detectSameKeywordMultiArticle($workspace, $threshold),
            $this->detectClusterMultiArticle($workspace, $threshold),
            $this->detectMultiClusterSameArticle($workspace),
            $this->detectPlannedVsExisting($workspace),
            $this->detectNearPrimaryConflict($workspace),
            $this->detectManualMappingConflict($workspace),
        );

        $persistIssues = (bool) ($options['persist'] ?? true);
        $risks = [];
        $seenFingerprints = [];

        foreach ($drafts as $draft) {
            $fingerprint = $this->fingerprint($workspace, $draft);
            $seenFingerprints[] = $fingerprint;

            $issue = $persistIssues ? $this->persistIssue($workspace, $draft, $fingerprint) : null;

            $risks[] = $this->toLegacyRisk($draft, $issue, $fingerprint);
        }

        if ($persistIssues) {
            $this->markStaleIssues($workspace, $seenFingerprints);
        }

        return $risks;
    }

    /**
     * @return list<array{
     *   issue_type: KeywordCannibalizationIssueType,
     *   risk_level: KeywordCannibalizationRiskLevel,
     *   keyword_ids: list<int>,
     *   cluster_ids: list<int>,
     *   article_ids: list<int>,
     *   reason_codes: list<string>,
     *   summary: string,
     *   recommended_action: KeywordCannibalizationRecommendedAction,
     *   confidence: float,
     *   legacy_keyword: string|null,
     *   legacy_cluster_name: string|null
     * }>
     */
    private function detectSameKeywordMultiArticle(SeoKeywordWorkspace $workspace, int $threshold): array
    {
        $grouped = SeoKeywordArticleMapping::query()
            ->where('workspace_id', $workspace->id)
            ->where('mapping_type', KeywordArticleMappingType::CurrentContent->value)
            ->whereNotNull('article_id')
            ->get()
            ->groupBy('keyword_id');

        $drafts = [];

        foreach ($grouped as $keywordId => $group) {
            $articleIds = $group->pluck('article_id')->map(static fn ($id): int => (int) $id)->unique()->values();
            if ($articleIds->count() < $threshold) {
                continue;
            }

            $keyword = SeoKiKeyword::query()->find($keywordId);
            if (! $keyword instanceof SeoKiKeyword) {
                continue;
            }

            $riskLevel = $this->riskLevelForCount($articleIds->count());

            $drafts[] = [
                'issue_type' => KeywordCannibalizationIssueType::SameKeywordMultiArticle,
                'risk_level' => $riskLevel,
                'keyword_ids' => [(int) $keyword->id],
                'cluster_ids' => $keyword->cluster_id !== null ? [(int) $keyword->cluster_id] : [],
                'article_ids' => $articleIds->all(),
                'reason_codes' => ['keyword.cannibalization.same_keyword_multi_article'],
                'summary' => sprintf(
                    'Keyword "%s" currently targets %d different articles — pick one canonical target.',
                    (string) $keyword->keyword,
                    $articleIds->count(),
                ),
                'recommended_action' => in_array($riskLevel, [KeywordCannibalizationRiskLevel::High, KeywordCannibalizationRiskLevel::Critical], true)
                    ? KeywordCannibalizationRecommendedAction::RewriteExisting
                    : KeywordCannibalizationRecommendedAction::MapToExisting,
                'confidence' => 0.9,
                'legacy_keyword' => (string) $keyword->keyword,
                'legacy_cluster_name' => null,
            ];
        }

        return $drafts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function detectClusterMultiArticle(SeoKeywordWorkspace $workspace, int $threshold): array
    {
        $clusters = SeoKeywordCluster::query()
            ->where('workspace_id', $workspace->id)
            ->with('keywords:id,cluster_id')
            ->get();

        $drafts = [];

        foreach ($clusters as $cluster) {
            $keywordIds = $cluster->keywords->pluck('id');
            if ($keywordIds->isEmpty()) {
                continue;
            }

            $articleIds = SeoKeywordArticleMapping::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('keyword_id', $keywordIds)
                ->where('mapping_type', KeywordArticleMappingType::CurrentContent->value)
                ->whereNotNull('article_id')
                ->distinct()
                ->pluck('article_id')
                ->map(static fn ($id): int => (int) $id);

            if ($articleIds->count() < $threshold) {
                continue;
            }

            $riskLevel = $this->riskLevelForCount($articleIds->count());

            $drafts[] = [
                'issue_type' => KeywordCannibalizationIssueType::ClusterMultiArticle,
                'risk_level' => $riskLevel,
                'keyword_ids' => $keywordIds->map(static fn ($id): int => (int) $id)->all(),
                'cluster_ids' => [(int) $cluster->id],
                'article_ids' => $articleIds->all(),
                'reason_codes' => ['keyword.cannibalization.cluster_multi_article'],
                'summary' => sprintf(
                    'Cluster "%s" spreads across %d different articles — merge or differentiate.',
                    (string) $cluster->name,
                    $articleIds->count(),
                ),
                'recommended_action' => in_array($riskLevel, [KeywordCannibalizationRiskLevel::High, KeywordCannibalizationRiskLevel::Critical], true)
                    ? KeywordCannibalizationRecommendedAction::MergeClusters
                    : KeywordCannibalizationRecommendedAction::DifferentiateIntent,
                'confidence' => 0.75,
                'legacy_keyword' => null,
                'legacy_cluster_name' => (string) $cluster->name,
            ];
        }

        return $drafts;
    }

    /**
     * Không false-positive: chỉ tính mapping PRIMARY keyword — 1 bài viết phục vụ nhiều
     * secondary keyword của cùng 1 cluster là bình thường, không phải cannibalization.
     *
     * @return list<array<string, mixed>>
     */
    private function detectMultiClusterSameArticle(SeoKeywordWorkspace $workspace): array
    {
        $primaryKeywords = SeoKiKeyword::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_primary', true)
            ->whereNotNull('cluster_id')
            ->get(['id', 'cluster_id', 'keyword']);

        if ($primaryKeywords->isEmpty()) {
            return [];
        }

        $mappings = SeoKeywordArticleMapping::query()
            ->where('workspace_id', $workspace->id)
            ->where('mapping_type', KeywordArticleMappingType::CurrentContent->value)
            ->whereNotNull('article_id')
            ->whereIn('keyword_id', $primaryKeywords->pluck('id'))
            ->get(['keyword_id', 'article_id']);

        if ($mappings->isEmpty()) {
            return [];
        }

        $primaryById = $primaryKeywords->keyBy('id');

        /** @var array<int, array{clusters: array<int, bool>, keyword_ids: array<int, bool>}> $byArticle */
        $byArticle = [];
        foreach ($mappings as $mapping) {
            $keyword = $primaryById->get((int) $mapping->keyword_id);
            if (! $keyword instanceof SeoKiKeyword) {
                continue;
            }
            $articleId = (int) $mapping->article_id;
            $byArticle[$articleId]['clusters'][(int) $keyword->cluster_id] = true;
            $byArticle[$articleId]['keyword_ids'][(int) $keyword->id] = true;
        }

        $drafts = [];
        foreach ($byArticle as $articleId => $data) {
            $clusterIds = array_keys($data['clusters']);
            if (count($clusterIds) < 2) {
                continue;
            }

            $riskLevel = $this->riskLevelForCount(count($clusterIds) + 1);

            $drafts[] = [
                'issue_type' => KeywordCannibalizationIssueType::MultiClusterSameArticle,
                'risk_level' => $riskLevel,
                'keyword_ids' => array_keys($data['keyword_ids']),
                'cluster_ids' => $clusterIds,
                'article_ids' => [$articleId],
                'reason_codes' => ['keyword.cannibalization.multi_cluster_same_article'],
                'summary' => sprintf(
                    'Article is targeted as the primary destination by %d different clusters — consolidate ownership.',
                    count($clusterIds),
                ),
                'recommended_action' => count($clusterIds) > 2
                    ? KeywordCannibalizationRecommendedAction::MergeClusters
                    : KeywordCannibalizationRecommendedAction::ChangePrimaryKeyword,
                'confidence' => 0.7,
                'legacy_keyword' => null,
                'legacy_cluster_name' => null,
            ];
        }

        return $drafts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function detectPlannedVsExisting(SeoKeywordWorkspace $workspace): array
    {
        $plannedKeywordIds = SeoKeywordArticleMapping::query()
            ->where('workspace_id', $workspace->id)
            ->where('mapping_type', KeywordArticleMappingType::PlannedTarget->value)
            ->pluck('keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique();

        if ($plannedKeywordIds->isEmpty()) {
            return [];
        }

        $currentMappings = SeoKeywordArticleMapping::query()
            ->where('workspace_id', $workspace->id)
            ->where('mapping_type', KeywordArticleMappingType::CurrentContent->value)
            ->whereNotNull('article_id')
            ->whereIn('keyword_id', $plannedKeywordIds)
            ->get()
            ->groupBy('keyword_id');

        $drafts = [];
        foreach ($currentMappings as $keywordId => $group) {
            $keyword = SeoKiKeyword::query()->find($keywordId);
            if (! $keyword instanceof SeoKiKeyword) {
                continue;
            }

            $articleIds = $group->pluck('article_id')->map(static fn ($id): int => (int) $id)->unique()->values();

            $drafts[] = [
                'issue_type' => KeywordCannibalizationIssueType::PlannedVsExisting,
                'risk_level' => KeywordCannibalizationRiskLevel::High,
                'keyword_ids' => [(int) $keyword->id],
                'cluster_ids' => $keyword->cluster_id !== null ? [(int) $keyword->cluster_id] : [],
                'article_ids' => $articleIds->all(),
                'reason_codes' => ['keyword.cannibalization.planned_vs_existing'],
                'summary' => sprintf(
                    'Keyword "%s" is planned for a new article while %d existing article(s) already target it.',
                    (string) $keyword->keyword,
                    $articleIds->count(),
                ),
                'recommended_action' => KeywordCannibalizationRecommendedAction::MapToExisting,
                'confidence' => 0.8,
                'legacy_keyword' => (string) $keyword->keyword,
                'legacy_cluster_name' => null,
            ];
        }

        return $drafts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function detectNearPrimaryConflict(SeoKeywordWorkspace $workspace): array
    {
        $primaries = SeoKiKeyword::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_primary', true)
            ->whereNotNull('cluster_id')
            ->orderBy('id')
            ->get(['id', 'cluster_id', 'keyword', 'normalized_keyword']);

        $count = $primaries->count();
        if ($count < 2) {
            return [];
        }

        $normalizer = new KeywordNormalizationService;
        $list = $primaries->values();
        $seenPairs = [];
        $drafts = [];

        for ($i = 0; $i < $count; $i++) {
            $a = $list->get($i);
            for ($j = $i + 1; $j < $count; $j++) {
                $b = $list->get($j);
                if ((int) $a->cluster_id === (int) $b->cluster_id) {
                    continue;
                }

                $pairKey = min((int) $a->id, (int) $b->id).':'.max((int) $a->id, (int) $b->id);
                if (isset($seenPairs[$pairKey])) {
                    continue;
                }

                if (! $normalizer->isNearDuplicate((string) $a->normalized_keyword, (string) $b->normalized_keyword)) {
                    continue;
                }

                $seenPairs[$pairKey] = true;

                $drafts[] = [
                    'issue_type' => KeywordCannibalizationIssueType::NearPrimaryConflict,
                    'risk_level' => KeywordCannibalizationRiskLevel::Medium,
                    'keyword_ids' => [(int) $a->id, (int) $b->id],
                    'cluster_ids' => [(int) $a->cluster_id, (int) $b->cluster_id],
                    'article_ids' => [],
                    'reason_codes' => ['keyword.cannibalization.near_primary_conflict'],
                    'summary' => sprintf(
                        'Primary keywords "%s" and "%s" from different clusters are near-duplicates.',
                        (string) $a->keyword,
                        (string) $b->keyword,
                    ),
                    'recommended_action' => KeywordCannibalizationRecommendedAction::MergeKeywords,
                    'confidence' => 0.55,
                    'legacy_keyword' => (string) $a->keyword.' / '.(string) $b->keyword,
                    'legacy_cluster_name' => null,
                ];
            }
        }

        return $drafts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function detectManualMappingConflict(SeoKeywordWorkspace $workspace): array
    {
        $manualMappings = SeoKeywordArticleMapping::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_manual', true)
            ->get()
            ->groupBy('keyword_id');

        $drafts = [];

        foreach ($manualMappings as $keywordId => $group) {
            /** @var Collection<int, SeoKeywordArticleMapping> $group */
            $targets = $group
                ->map(static fn (SeoKeywordArticleMapping $m): string => $m->article_id !== null
                    ? 'article:'.$m->article_id
                    : 'ref:'.($m->external_reference ?? $m->mapping_type))
                ->unique();

            if ($targets->count() < 2) {
                continue;
            }

            $keyword = SeoKiKeyword::query()->find($keywordId);
            if (! $keyword instanceof SeoKiKeyword) {
                continue;
            }

            $articleIds = $group->pluck('article_id')->filter()->map(static fn ($id): int => (int) $id)->unique()->values();

            $drafts[] = [
                'issue_type' => KeywordCannibalizationIssueType::ManualMappingConflict,
                'risk_level' => KeywordCannibalizationRiskLevel::Medium,
                'keyword_ids' => [(int) $keyword->id],
                'cluster_ids' => $keyword->cluster_id !== null ? [(int) $keyword->cluster_id] : [],
                'article_ids' => $articleIds->all(),
                'reason_codes' => ['keyword.cannibalization.manual_mapping_conflict'],
                'summary' => sprintf(
                    'Keyword "%s" has %d conflicting manual mappings — needs human review.',
                    (string) $keyword->keyword,
                    $targets->count(),
                ),
                'recommended_action' => KeywordCannibalizationRecommendedAction::ManualReview,
                'confidence' => 0.85,
                'legacy_keyword' => (string) $keyword->keyword,
                'legacy_cluster_name' => null,
            ];
        }

        return $drafts;
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function fingerprint(SeoKeywordWorkspace $workspace, array $draft): string
    {
        $keywordIds = $draft['keyword_ids'];
        sort($keywordIds);
        $articleIds = $draft['article_ids'];
        sort($articleIds);
        $clusterIds = $draft['cluster_ids'];
        sort($clusterIds);

        $type = $draft['issue_type'] instanceof KeywordCannibalizationIssueType
            ? $draft['issue_type']->value
            : (string) $draft['issue_type'];

        return hash('xxh3', implode('|', [
            $workspace->id,
            $type,
            implode(',', $keywordIds),
            implode(',', $clusterIds),
            implode(',', $articleIds),
        ]));
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function persistIssue(SeoKeywordWorkspace $workspace, array $draft, string $fingerprint): ?SeoKeywordCannibalizationIssue
    {
        if (! class_exists(SeoKeywordCannibalizationIssue::class)) {
            return null;
        }

        try {
            $issueType = $draft['issue_type'] instanceof KeywordCannibalizationIssueType
                ? $draft['issue_type']
                : KeywordCannibalizationIssueType::from((string) $draft['issue_type']);
            $riskLevel = $draft['risk_level'] instanceof KeywordCannibalizationRiskLevel
                ? $draft['risk_level']
                : KeywordCannibalizationRiskLevel::from((string) $draft['risk_level']);
            $recommendedAction = $draft['recommended_action'] instanceof KeywordCannibalizationRecommendedAction
                ? $draft['recommended_action']
                : KeywordCannibalizationRecommendedAction::from((string) $draft['recommended_action']);

            $keywordRefs = array_map(static fn (int $id): string => KeywordIntelligencePublicRef::keyword($id), $draft['keyword_ids']);
            $clusterRefs = array_map(static fn (int $id): string => KeywordIntelligencePublicRef::cluster($id), $draft['cluster_ids']);
            $articleRefs = array_map(static fn (int $id): string => ContentProjectPublicRef::article($id), $draft['article_ids']);

            $issue = SeoKeywordCannibalizationIssue::query()
                ->where('workspace_id', $workspace->id)
                ->where('fingerprint', $fingerprint)
                ->first();

            if ($issue instanceof SeoKeywordCannibalizationIssue) {
                if (in_array((string) $issue->status, [
                    KeywordCannibalizationIssueStatus::Resolved->value,
                    KeywordCannibalizationIssueStatus::Ignored->value,
                ], true)) {
                    // Human đã xử lý — không ghi đè dữ liệu, chỉ giữ nguyên bản ghi.
                    return $issue;
                }

                $issue->risk_level = $riskLevel->value;
                $issue->keyword_refs = $keywordRefs;
                $issue->cluster_refs = $clusterRefs;
                $issue->article_refs = $articleRefs;
                $issue->reason_codes = $draft['reason_codes'];
                $issue->summary = $draft['summary'];
                $issue->recommended_action = $recommendedAction->value;
                $issue->confidence = $draft['confidence'];
                $issue->status = KeywordCannibalizationIssueStatus::Open->value;
                $issue->detected_at = now();
                $issue->save();

                return $issue;
            }

            $issue = new SeoKeywordCannibalizationIssue([
                'public_ref' => 'pending',
                'workspace_id' => $workspace->id,
                'tenant_id' => $workspace->tenant_id,
                'site_id' => $workspace->site_id,
                'issue_type' => $issueType->value,
                'risk_level' => $riskLevel->value,
                'status' => KeywordCannibalizationIssueStatus::Open->value,
                'keyword_refs' => $keywordRefs,
                'cluster_refs' => $clusterRefs,
                'article_refs' => $articleRefs,
                'reason_codes' => $draft['reason_codes'],
                'summary' => $draft['summary'],
                'recommended_action' => $recommendedAction->value,
                'confidence' => $draft['confidence'],
                'source' => 'rule',
                'fingerprint' => $fingerprint,
                'detected_at' => now(),
            ]);
            $issue->save();
            $issue->public_ref = KeywordIntelligencePublicRef::cannibalizationIssue((int) $issue->id);
            $issue->save();

            return $issue;
        } catch (Throwable) {
            // Persistence best-effort — detection result vẫn trả về cho caller dù DB ghi lỗi.
            return null;
        }
    }

    /**
     * @param  list<string>  $seenFingerprints
     */
    private function markStaleIssues(SeoKeywordWorkspace $workspace, array $seenFingerprints): void
    {
        if (! class_exists(SeoKeywordCannibalizationIssue::class)) {
            return;
        }

        try {
            SeoKeywordCannibalizationIssue::query()
                ->where('workspace_id', $workspace->id)
                ->whereNotIn('fingerprint', $seenFingerprints)
                ->whereIn('status', [
                    KeywordCannibalizationIssueStatus::Open->value,
                    KeywordCannibalizationIssueStatus::Reviewed->value,
                ])
                ->update(['status' => KeywordCannibalizationIssueStatus::Stale->value]);
        } catch (Throwable) {
            // best-effort — không chặn pipeline vì lỗi cleanup.
        }
    }

    /**
     * Legacy shape (list<array>) — giữ tương thích cho ViewKeywordWorkspace blade +
     * KeywordIntelligenceReadService (đọc 'type', 'keyword'/'cluster_name', 'risk_level',
     * 'article_refs', 'recommended_action').
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function toLegacyRisk(array $draft, ?SeoKeywordCannibalizationIssue $issue, string $fingerprint): array
    {
        $issueType = $draft['issue_type'] instanceof KeywordCannibalizationIssueType
            ? $draft['issue_type']->value
            : (string) $draft['issue_type'];
        $riskLevel = $draft['risk_level'] instanceof KeywordCannibalizationRiskLevel
            ? $draft['risk_level']->value
            : (string) $draft['risk_level'];
        $recommendedAction = $draft['recommended_action'] instanceof KeywordCannibalizationRecommendedAction
            ? $draft['recommended_action']->value
            : (string) $draft['recommended_action'];

        return [
            'type' => $issueType,
            'issue_type' => $issueType,
            'issue_ref' => $issue?->public_ref,
            'fingerprint' => $fingerprint,
            'status' => $issue?->status instanceof KeywordCannibalizationIssueStatus ? $issue->status->value : ($issue?->status ?? KeywordCannibalizationIssueStatus::Open->value),
            'keyword' => $draft['legacy_keyword'],
            'cluster_name' => $draft['legacy_cluster_name'],
            'cluster_ref' => $draft['cluster_ids'] !== [] ? KeywordIntelligencePublicRef::cluster((int) $draft['cluster_ids'][0]) : null,
            'keyword_refs' => array_map(static fn (int $id): string => KeywordIntelligencePublicRef::keyword($id), $draft['keyword_ids']),
            'article_refs' => array_map(static fn (int $id): string => ContentProjectPublicRef::article($id), $draft['article_ids']),
            'risk_level' => $riskLevel,
            'confidence' => $draft['confidence'],
            'recommended_action' => $draft['summary'],
            'recommended_action_code' => $recommendedAction,
        ];
    }

    private function riskLevelForCount(int $count): KeywordCannibalizationRiskLevel
    {
        return match (true) {
            $count >= 5 => KeywordCannibalizationRiskLevel::Critical,
            $count >= 4 => KeywordCannibalizationRiskLevel::High,
            $count >= 3 => KeywordCannibalizationRiskLevel::Medium,
            default => KeywordCannibalizationRiskLevel::Low,
        };
    }

    private function config(string $key, mixed $default): mixed
    {
        if (! function_exists('config')) {
            return $default;
        }

        try {
            return config($key, $default);
        } catch (Throwable) {
            return $default;
        }
    }
}
