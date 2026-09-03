<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordAnalysisStage;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordAnalysisStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordArticleMappingType;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordWorkspaceStatus;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordAnalysisOperation;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordArticleMapping;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKiKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use RuntimeException;
use Throwable;

/**
 * Phase 2 analysis pipeline:
 * normalize → deduplicate → classify → score → map_existing → cluster → finalize
 * No Topical Map / Content Project in this phase.
 */
final class KeywordWorkspaceAnalysisService
{
    public function __construct(
        private readonly KeywordNormalizationService $normalization,
        private readonly KeywordDuplicateResolver $duplicateResolver,
        private readonly KeywordNearDuplicateDetector $nearDuplicateDetector,
        private readonly KeywordIntentClassifier $intentClassifier,
        private readonly KeywordScoringService $scoringService,
        private readonly KeywordExistingContentMapper $contentMapper,
        private readonly KeywordExistingContentIndex $contentIndex,
        private readonly KeywordClusterService $clusterService,
        private readonly KeywordWorkspaceAnalysisLock $analysisLock,
        private readonly KeywordManualOverrideGuard $overrideGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array{operation_id: int, operation_ref: string, summary: array<string, mixed>, status: string, result_code: string}
     */
    public function analyze(SeoKeywordWorkspace $workspace, array $options = []): array
    {
        $strategy = (string) ($options['strategy'] ?? $options['clustering_strategy'] ?? 'balanced');
        $idempotencyKey = isset($options['idempotency_key']) ? trim((string) $options['idempotency_key']) : '';
        $preserveManual = (bool) ($options['preserve_manual_overrides'] ?? true);
        $useAiIntent = (bool) ($options['use_ai_intent'] ?? false);
        $rebuildIndex = (bool) ($options['rebuild_content_index'] ?? false);
        $reclusterDraftOnly = (bool) ($options['recluster_draft_only'] ?? true);

        if ($idempotencyKey !== '') {
            $existing = SeoKeywordAnalysisOperation::query()
                ->where('workspace_id', $workspace->id)
                ->where('idempotency_key', $idempotencyKey)
                ->orderByDesc('id')
                ->first();
            if ($existing instanceof SeoKeywordAnalysisOperation
                && in_array((string) $existing->status, ['accepted', 'processing', 'running', 'completed', 'partially_completed'], true)) {
                return [
                    'operation_id' => (int) $existing->id,
                    'operation_ref' => (string) $existing->public_ref,
                    'summary' => (array) ($existing->summary ?? []),
                    'status' => (string) $existing->status,
                    'result_code' => (string) ($existing->result_code ?? KeywordIntelligenceActionCodes::ANALYZED),
                ];
            }
        }

        $ownerToken = $this->analysisLock->acquire((string) $workspace->public_ref, 0);
        if ($ownerToken === null) {
            throw new RuntimeException('keyword.analysis_already_processing');
        }

        $operation = $this->startOperation($workspace, $options, $idempotencyKey, $ownerToken);
        $warnings = [];
        $partial = false;

        try {
            $workspace->status = KeywordWorkspaceStatus::Analyzing->value;
            $workspace->save();

            $keywordQuery = SeoKiKeyword::query()->where('workspace_id', $workspace->id);
            $scopeRefs = $options['keyword_refs'] ?? null;
            if (is_array($scopeRefs) && $scopeRefs !== []) {
                $ids = [];
                foreach ($scopeRefs as $ref) {
                    try {
                        $ids[] = KeywordIntelligencePublicRef::resolveKeywordIdStrict((string) $ref);
                    } catch (Throwable) {
                        // skip invalid
                    }
                }
                if ($ids !== []) {
                    $keywordQuery->whereIn('id', $ids);
                    $operation->keyword_scope = $ids;
                }
            }

            $total = (clone $keywordQuery)->count();
            $max = $this->configInt('seo-content-ai.keyword_intelligence.analysis.max_keywords_per_analysis', 5000);
            if ($total > $max) {
                throw new RuntimeException('keyword.analysis_too_large');
            }

            $operation->total_keywords = $total;
            $operation->status = 'processing';
            $operation->started_at = now();
            $operation->save();

            $this->assertNotCancelled($operation);
            $this->advance($operation, KeywordAnalysisStage::Normalizing, 8, 'keyword.normalize.completed', (string) $workspace->public_ref, $ownerToken);
            $normalized = $this->stageNormalize($keywordQuery, $preserveManual);

            $this->assertNotCancelled($operation);
            $this->advance($operation, KeywordAnalysisStage::Deduplicate, 18, 'keyword.deduplicate.completed', (string) $workspace->public_ref, $ownerToken);
            $nearCandidates = $this->nearDuplicateDetector->detectCandidates($workspace);
            $this->nearDuplicateDetector->persistCandidates($workspace, $nearCandidates);
            $dedup = ['candidates' => $nearCandidates, 'warnings' => []];
            $warnings = array_merge($warnings, $dedup['warnings']);

            $this->assertNotCancelled($operation);
            $this->advance($operation, KeywordAnalysisStage::ClassifyingIntent, 32, 'keyword.intent.completed', (string) $workspace->public_ref, $ownerToken);
            $classified = $this->stageClassify($keywordQuery, $preserveManual, $useAiIntent);
            if ($classified['needs_review'] > 0) {
                $partial = true;
                $warnings[] = 'keyword.intent_ai_partial_failure';
            }

            $this->assertNotCancelled($operation);
            $this->advance($operation, KeywordAnalysisStage::Scoring, 48, 'keyword.score.completed', (string) $workspace->public_ref, $ownerToken);
            $scored = $this->stageScore($keywordQuery, $preserveManual);

            $this->assertNotCancelled($operation);
            $this->advance($operation, KeywordAnalysisStage::MappingContent, 62, 'keyword.mapping.completed', (string) $workspace->public_ref, $ownerToken);
            if ($rebuildIndex) {
                $this->contentIndex->buildAndCacheForWorkspace($workspace);
            }
            $mapping = $this->contentMapper->mapWorkspace($workspace);

            $this->assertNotCancelled($operation);
            $this->advance($operation, KeywordAnalysisStage::Clustering, 78, 'keyword.clustering.completed', (string) $workspace->public_ref, $ownerToken);
            $clusters = $this->clusterService->clusterWorkspace($workspace, $strategy, [
                'recluster_draft_only' => $reclusterDraftOnly,
                'preserve_manual_overrides' => $preserveManual,
            ]);

            $this->advance($operation, KeywordAnalysisStage::Finalize, 96, 'keyword.analysis.completed', (string) $workspace->public_ref, $ownerToken);

            $summary = [
                'normalized' => $normalized,
                'near_duplicates' => count($dedup['candidates']),
                'classified' => $classified,
                'scored' => $scored,
                'content_mapping' => $mapping,
                'cluster_count' => is_countable($clusters) ? count($clusters) : 0,
                'warnings' => $warnings,
            ];

            $status = $partial ? 'partially_completed' : 'completed';
            $resultCode = $partial
                ? 'keyword.analysis.partially_completed'
                : 'keyword.analysis.completed';

            $operation->status = $status;
            $operation->stage = KeywordAnalysisStage::Completed->value;
            $operation->current_stage = KeywordAnalysisStage::Completed->value;
            $operation->progress = 100;
            $operation->progress_percent = 100;
            $operation->processed_keywords = $total;
            $operation->failed_keywords = (int) ($normalized['failed'] ?? 0);
            $operation->warnings_count = count($warnings);
            $operation->summary = $summary;
            $operation->result_code = $resultCode;
            $operation->finished_at = now();
            $operation->save();

            $workspace->status = KeywordWorkspaceStatus::Ready->value;
            $workspace->last_analyzed_at = now();
            $workspace->summary = $summary;
            $workspace->save();

            return [
                'operation_id' => (int) $operation->id,
                'operation_ref' => (string) $operation->public_ref,
                'summary' => $summary,
                'status' => $status,
                'result_code' => $resultCode,
            ];
        } catch (Throwable $e) {
            $operation->status = str_contains($e->getMessage(), 'cancelled') ? 'cancelled' : 'failed';
            $operation->stage = KeywordAnalysisStage::Failed->value;
            $operation->current_stage = KeywordAnalysisStage::Failed->value;
            $operation->error = $e->getMessage();
            $operation->result_code = str_contains($e->getMessage(), 'analysis_too_large')
                ? KeywordIntelligenceActionCodes::ANALYSIS_TOO_LARGE
                : 'keyword.analysis.failed';
            $operation->finished_at = now();
            $operation->save();

            $workspace->status = KeywordWorkspaceStatus::Draft->value;
            $workspace->save();

            throw $e;
        } finally {
            $this->analysisLock->release((string) $workspace->public_ref, $ownerToken);
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function startOperation(
        SeoKeywordWorkspace $workspace,
        array $options,
        string $idempotencyKey,
        string $ownerToken,
    ): SeoKeywordAnalysisOperation {
        $operation = new SeoKeywordAnalysisOperation([
            'public_ref' => 'pending',
            'workspace_id' => $workspace->id,
            'tenant_id' => $workspace->tenant_id,
            'site_id' => $workspace->site_id,
            'status' => 'accepted',
            'stage' => KeywordAnalysisStage::Normalizing->value,
            'current_stage' => KeywordAnalysisStage::Normalizing->value,
            'progress' => 1,
            'progress_percent' => 1,
            'options' => $options,
            'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
            'lock_owner_token' => $ownerToken,
            'cancel_requested' => false,
        ]);
        $operation->save();
        $operation->public_ref = KeywordIntelligencePublicRef::operation((int) $operation->id);
        $operation->save();

        return $operation;
    }

    private function advance(
        SeoKeywordAnalysisOperation $operation,
        KeywordAnalysisStage $stage,
        int $progress,
        string $resultCode,
        string $workspaceRef = '',
        string $ownerToken = '',
    ): void {
        if ($workspaceRef !== '' && $ownerToken !== '') {
            $this->analysisLock->refresh($workspaceRef, $ownerToken);
        }
        $operation->stage = $stage->value;
        $operation->current_stage = $stage->value;
        $operation->progress = $progress;
        $operation->progress_percent = $progress;
        $operation->result_code = $resultCode;
        $operation->save();
    }

    private function assertNotCancelled(SeoKeywordAnalysisOperation $operation): void
    {
        $operation->refresh();
        if ($operation->cancel_requested || (string) $operation->status === 'cancelled') {
            throw new RuntimeException('keyword.analysis.cancelled');
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<SeoKiKeyword>  $baseQuery
     * @return array{processed: int, failed: int}
     */
    private function stageNormalize($baseQuery, bool $preserveManual): array
    {
        $processed = 0;
        $failed = 0;
        (clone $baseQuery)->orderBy('id')->chunkById(200, function ($keywords) use (&$processed, &$failed, $preserveManual): void {
            foreach ($keywords as $keyword) {
                if ($preserveManual && $this->overrideGuard->isManual($keyword, 'normalized_keyword')) {
                    $processed++;

                    continue;
                }

                $result = $this->normalization->analyze((string) $keyword->keyword);
                if (! $result->isValid) {
                    $keyword->analysis_status = KeywordAnalysisStatus::Failed->value;
                    $keyword->is_excluded = true;
                    $keyword->metadata = array_merge((array) ($keyword->metadata ?? []), [
                        'failure_code' => $result->failureCode,
                        'normalization_warnings' => $result->warnings,
                    ]);
                    $keyword->save();
                    $failed++;

                    continue;
                }
                $keyword->normalized_keyword = $result->normalized;
                $keyword->save();
                $processed++;
            }
        });

        return ['processed' => $processed, 'failed' => $failed];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<SeoKiKeyword>  $baseQuery
     * @return array{classified: int, needs_review: int}
     */
    private function stageClassify($baseQuery, bool $preserveManual, bool $useAi): array
    {
        $count = 0;
        $needsReview = 0;
        (clone $baseQuery)->where('is_excluded', false)->orderBy('id')->chunkById(200, function ($keywords) use (&$count, &$needsReview, $preserveManual, $useAi): void {
            foreach ($keywords as $keyword) {
                if ($preserveManual && $this->overrideGuard->isManual($keyword, 'search_intent')) {
                    $count++;

                    continue;
                }

                if (method_exists($this->intentClassifier, 'classifyWithOptionalAi')) {
                    $result = $this->intentClassifier->classifyWithOptionalAi(
                        (string) $keyword->keyword,
                        (string) $keyword->normalized_keyword,
                        ['use_ai' => $useAi],
                    );
                    $keyword->search_intent = $result->primaryIntent->value;
                    $keyword->secondary_intents = $result->secondaryIntents;
                    $keyword->funnel_stage = $result->funnel->value;
                    $sources = (array) ($keyword->field_sources ?? []);
                    $sources['search_intent'] = [
                        'source' => $result->source,
                        'updated_at' => gmdate('c'),
                        'confidence' => $result->confidence,
                    ];
                    $keyword->field_sources = $sources;
                    if ($result->source === 'needs_review') {
                        $needsReview++;
                    }
                } else {
                    $result = $this->intentClassifier->classify((string) $keyword->keyword, (string) $keyword->normalized_keyword);
                    $keyword->search_intent = $result['primary']->value;
                    $keyword->secondary_intents = $result['secondary'];
                    $keyword->funnel_stage = $result['funnel']->value;
                }
                $keyword->save();
                $count++;
            }
        });

        return ['classified' => $count, 'needs_review' => $needsReview];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<SeoKiKeyword>  $baseQuery
     * @return array{scored: int}
     */
    private function stageScore($baseQuery, bool $preserveManual): array
    {
        $count = 0;
        $hasCoverage = [];
        $sample = (clone $baseQuery)->first();
        if ($sample instanceof SeoKiKeyword) {
            $hasCoverage = SeoKeywordArticleMapping::query()
                ->where('workspace_id', $sample->workspace_id)
                ->where('mapping_type', KeywordArticleMappingType::CurrentContent->value)
                ->whereNotNull('article_id')
                ->pluck('keyword_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        (clone $baseQuery)->where('is_excluded', false)->orderBy('id')->chunkById(200, function ($keywords) use (&$count, $hasCoverage, $preserveManual): void {
            foreach ($keywords as $keyword) {
                if ($preserveManual && $this->overrideGuard->isManual($keyword, 'priority_score')) {
                    $count++;

                    continue;
                }

                $intent = $keyword->search_intent instanceof \BackedEnum
                    ? $keyword->search_intent->value
                    : $keyword->search_intent;

                $scored = $this->scoringService->score([
                    'relevance' => $keyword->relevance_score !== null ? (float) $keyword->relevance_score : null,
                    'business_value' => $keyword->business_value_score !== null ? (float) $keyword->business_value_score : null,
                    'search_volume' => $keyword->search_volume,
                    'keyword_difficulty' => $keyword->keyword_difficulty !== null ? (float) $keyword->keyword_difficulty : null,
                    'competition' => $keyword->competition !== null ? (float) $keyword->competition : null,
                    'has_existing_coverage' => in_array((int) $keyword->id, $hasCoverage, true),
                    'intent' => $intent,
                ]);

                $keyword->relevance_score = $scored['relevance_score'];
                $keyword->opportunity_score = $scored['opportunity_score'];
                $keyword->total_score = $scored['priority_score'];
                $keyword->priority_score = $scored['priority_score'];
                $keyword->intent_score = $scored['confidence'] * 100;
                $keyword->analysis_status = KeywordAnalysisStatus::Analyzed->value;
                $keyword->analyzed_at = now();
                $keyword->metadata = array_merge((array) ($keyword->metadata ?? []), [
                    'score_factors' => $scored['score_factors'],
                    'score_version' => $scored['score_version'],
                    'score_warnings' => $scored['warnings'] ?? [],
                ]);
                $keyword->save();
                $count++;
            }
        });

        return ['scored' => $count];
    }

    private function configInt(string $key, int $default): int
    {
        if (! function_exists('config')) {
            return $default;
        }
        try {
            $v = (int) config($key, $default);

            return $v > 0 ? $v : $default;
        } catch (Throwable) {
            return $default;
        }
    }
}
