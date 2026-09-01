<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticlePendingInternalLinkService;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\CreateContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\KeywordProjectAssignmentService;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordFocusAttach;
use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\Seo\Services\SeoIssueProjectTaskAssignmentService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Canonical Add-to-Draft intake: resolve Shared Planning Draft, then create items.
 * Destination is never an execution Content Project from UI.
 */
final class PlanningDraftIntakeService
{
    public function __construct(
        private readonly PlanningDraftResolver $draftResolver,
        private readonly ContentProjectCommandBus $commandBus,
        private readonly SeoIssueProjectTaskAssignmentService $articleAssignment,
        private readonly KeywordProjectAssignmentService $keywordAssignment,
        private readonly SeoAnalyzerService $analyzer,
        private readonly ArticlePendingInternalLinkService $pendingLinks,
    ) {}

    /**
     * Find or create the canonical Shared Planning Draft.
     *
     * @param  int  $bootstrapSiteId  Required by create handler for tenant/quota; Draft project.site_id stays null.
     */
    public function ensureSharedDraft(?int $actorId = null, int $bootstrapSiteId = 0): SeoProject
    {
        return DB::connection('omi_seo_ai')->transaction(function () use ($actorId, $bootstrapSiteId): SeoProject {
            // Canonical only: status=draft AND site_id IS NULL. Never promote legacy per-site drafts.
            $existing = $this->draftResolver->findCanonicalSharedDraft();
            if ($existing instanceof SeoProject) {
                return $existing;
            }

            if ($bootstrapSiteId <= 0) {
                throw new InvalidArgumentException('bootstrap_site_id is required to create Shared Draft.');
            }

            // Lock bootstrap site row scope to serialize concurrent first-create.
            SeoProject::query()
                ->where('status', SeoProject::STATUS_DRAFT)
                ->whereNull('site_id')
                ->whereNull('archived_at')
                ->lockForUpdate()
                ->get();

            $existing = $this->draftResolver->findCanonicalSharedDraft();
            if ($existing instanceof SeoProject) {
                return $existing;
            }

            $actorId = $actorId !== null && $actorId > 0
                ? $actorId
                : (auth()->id() !== null ? (int) auth()->id() : null);

            $result = $this->commandBus->dispatch(
                new CreateContentProjectCommand([
                    'name' => SeoProject::defaultDraftName(),
                    'site_id' => $bootstrapSiteId,
                    'status' => SeoProject::STATUS_DRAFT,
                    'user_id' => $actorId,
                    'month' => SeoProject::draftCompatibilityMonth(),
                ]),
                ActorContext::user($actorId, $bootstrapSiteId),
            );

            if (! $result->success || $result->projectId === null || $result->projectId <= 0) {
                throw new RuntimeException($result->message !== '' ? $result->message : 'Failed to ensure Shared Draft.');
            }

            $draft = SeoProject::query()->find($result->projectId);
            if (! $draft instanceof SeoProject || ! $draft->isDraftPlanning()) {
                throw new RuntimeException('Shared Draft resolve failed after create.');
            }

            // Create handler already sets site_id null for Draft; enforce invariant.
            if ($draft->site_id !== null) {
                $draft->forceFill(['site_id' => null])->save();
                $draft = $draft->fresh() ?? $draft;
            }

            return $draft;
        });
    }

    public function articleNeedsKeyword(SeoArticle $article): bool
    {
        return trim((string) ($this->analyzer->resolveFocusKeywordForArticle($article) ?? '')) === '';
    }

    /**
     * @param  Collection<int, SeoArticle>|iterable<int, SeoArticle>  $articles
     */
    public function anyArticleNeedsKeyword(iterable $articles): bool
    {
        foreach ($articles as $article) {
            if ($article instanceof SeoArticle && $this->articleNeedsKeyword($article)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, SeoArticle>|iterable<int, SeoArticle>  $articles
     */
    public function addArticles(
        iterable $articles,
        ?string $keyword = null,
        ?string $forcedType = null,
        ?int $actorId = null,
    ): PlanningDraftIntakeResult {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            return new PlanningDraftIntakeResult(
                PlanningDraftIntakeResult::STATUS_FAILED,
                null,
                message: __('seo-content-ai::filament.articles_optimal.assign_failed'),
            );
        }

        $records = Collection::make($articles)
            ->filter(static fn (mixed $row): bool => $row instanceof SeoArticle)
            ->values();

        if ($records->isEmpty()) {
            return new PlanningDraftIntakeResult(
                PlanningDraftIntakeResult::STATUS_FAILED,
                null,
                message: __('seo-content-ai::filament.keyword.workspace_article_not_found'),
            );
        }

        $bootstrapSiteId = 0;
        foreach ($records as $article) {
            $siteId = (int) ($article->site_id ?? 0);
            if ($siteId > 0) {
                $bootstrapSiteId = $siteId;
                break;
            }
        }

        if ($bootstrapSiteId <= 0) {
            return new PlanningDraftIntakeResult(
                PlanningDraftIntakeResult::STATUS_SITE_NOT_RESOLVED,
                null,
                summary: ['site_not_resolved' => $records->count()],
                message: (string) __('seo-content-ai::filament.article_list.site_not_resolved'),
            );
        }

        $unresolved = $records->filter(static fn (SeoArticle $a): bool => (int) ($a->site_id ?? 0) <= 0);
        if ($unresolved->isNotEmpty() && $unresolved->count() === $records->count()) {
            return new PlanningDraftIntakeResult(
                PlanningDraftIntakeResult::STATUS_SITE_NOT_RESOLVED,
                null,
                summary: ['site_not_resolved' => $unresolved->count()],
                message: (string) __('seo-content-ai::filament.article_list.site_not_resolved'),
            );
        }

        // Prefer articles with real site_id; never invent site from global selector.
        $records = $records
            ->filter(static fn (SeoArticle $a): bool => (int) ($a->site_id ?? 0) > 0)
            ->values();

        if ($records->isEmpty()) {
            return new PlanningDraftIntakeResult(
                PlanningDraftIntakeResult::STATUS_SITE_NOT_RESOLVED,
                null,
                summary: ['site_not_resolved' => 1],
                message: (string) __('seo-content-ai::filament.article_list.site_not_resolved'),
            );
        }

        $keywordInput = trim((string) ($keyword ?? ''));
        $needsKeyword = $this->anyArticleNeedsKeyword($records);
        if ($needsKeyword && $keywordInput === '') {
            return new PlanningDraftIntakeResult(
                PlanningDraftIntakeResult::STATUS_MISSING_KEYWORD,
                null,
                message: __('seo-content-ai::filament.articles_optimal.assign_missing_keyword_required'),
            );
        }

        $actorId = $actorId !== null && $actorId > 0
            ? $actorId
            : (auth()->id() !== null ? (int) auth()->id() : 0);

        try {
            $draft = $this->ensureSharedDraft($actorId > 0 ? $actorId : null, $bootstrapSiteId);
        } catch (Throwable $e) {
            return new PlanningDraftIntakeResult(
                PlanningDraftIntakeResult::STATUS_DRAFT_NOT_RESOLVED,
                null,
                message: $e->getMessage(),
            );
        }

        $draftId = (int) $draft->getKey();
        $articleIds = $records->map(static fn (SeoArticle $a): int => (int) $a->getKey())->all();

        $connection = $draft->getConnectionName();

        try {
            $summary = DB::connection($connection)->transaction(function () use (
                $records,
                $keywordInput,
                $needsKeyword,
                $actorId,
                $draftId,
                $forcedType,
            ): array {
                if ($needsKeyword && $keywordInput !== '') {
                    foreach ($records as $article) {
                        if (! $this->articleNeedsKeyword($article)) {
                            continue;
                        }
                        $siteId = (int) ($article->site_id ?? 0);
                        if ($siteId <= 0) {
                            continue;
                        }
                        KeywordFocusAttach::syncMainKeyword($article, $siteId, $actorId, $keywordInput);
                        $article->unsetRelation('articleMetas');
                    }
                }

                $taskType = $forcedType !== null && trim($forcedType) !== ''
                    ? SeoProjectTask::normalizeType($forcedType)
                    : SeoProjectTask::TYPE_REWRITE;

                return $this->articleAssignment->assignArticles(
                    $records,
                    $draftId,
                    $taskType,
                    SeoProjectTask::REWRITE_MODE_CONTENT,
                    null,
                    $keywordInput !== '' ? $keywordInput : null,
                    null,
                    false,
                    true,
                );
            });
        } catch (Throwable $e) {
            return new PlanningDraftIntakeResult(
                PlanningDraftIntakeResult::STATUS_FAILED,
                $draftId,
                message: $e->getMessage(),
                articleIds: $articleIds,
            );
        }

        $summary = $this->normalizeDraftSummary($summary);
        $summary = $this->confirmPersistedArticleAdds($summary, $draftId, $articleIds);

        $result = PlanningDraftIntakeResult::fromAssignmentSummary(
            $summary,
            $draftId,
            (string) __('seo-content-ai::filament.article_list.add_to_draft_completed'),
            (string) __('seo-content-ai::filament.article_list.already_in_draft'),
            $this->buildDraftSummaryMessage($summary),
            $articleIds,
        );

        $this->logIntakeOutcome('article', $result, $bootstrapSiteId, $articleIds);

        return $result;
    }

    /**
     * @param  Collection<int, Keyword>|iterable<int, Keyword>  $keywords
     * @param  list<int>  $siteIds
     */
    public function addKeywords(iterable $keywords, array $siteIds, ?int $actorId = null): PlanningDraftIntakeResult
    {
        $records = Collection::make($keywords)
            ->filter(static fn (mixed $row): bool => $row instanceof Keyword)
            ->values();

        $normalizedSites = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $siteIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($records->isEmpty() || $normalizedSites === []) {
            return new PlanningDraftIntakeResult(
                PlanningDraftIntakeResult::STATUS_FAILED,
                null,
                message: __('seo-content-ai::filament.articles_optimal.assign_failed'),
            );
        }

        $bootstrapSiteId = $normalizedSites[0];
        $actorId = $actorId !== null && $actorId > 0
            ? $actorId
            : (auth()->id() !== null ? (int) auth()->id() : null);

        try {
            $draft = $this->ensureSharedDraft($actorId, $bootstrapSiteId);
        } catch (Throwable $e) {
            return new PlanningDraftIntakeResult(
                PlanningDraftIntakeResult::STATUS_DRAFT_NOT_RESOLVED,
                null,
                message: $e->getMessage(),
            );
        }

        $draftId = (int) $draft->getKey();
        $keywordIds = $records->map(static fn (Keyword $k): int => (int) $k->getKey())->all();

        $merged = [
            'added' => 0,
            'duplicate' => 0,
            'overflow' => 0,
            'domain_mismatch' => 0,
            'already_in_project' => 0,
            'allocations' => [],
        ];

        foreach ($normalizedSites as $siteId) {
            if (! SeoAccessControl::canAccessSite($siteId)) {
                $merged['domain_mismatch'] += $records->count();
                continue;
            }

            $summary = $this->keywordAssignment->assignKeywords($records, $draftId, $siteId, false);
            foreach (['added', 'duplicate', 'overflow', 'domain_mismatch', 'already_in_project'] as $key) {
                $merged[$key] = (int) ($merged[$key] ?? 0) + (int) ($summary[$key] ?? 0);
            }
            foreach ($summary['allocations'] ?? [] as $row) {
                $merged['allocations'][] = $row;
            }
        }

        $normalized = $this->normalizeDraftSummary($merged);
        $result = PlanningDraftIntakeResult::fromAssignmentSummary(
            $normalized,
            $draftId,
            (string) __('seo-content-ai::filament.keyword.add_to_draft_completed'),
            (string) __('seo-content-ai::filament.article_list.already_in_draft'),
            $this->buildDraftSummaryMessage($normalized),
            keywordIds: $keywordIds,
        );
        $this->logIntakeOutcome('keyword', $result, $bootstrapSiteId, keywordIds: $keywordIds);

        return $result;
    }

    /**
     * @param  list<string|array{keyword?: string, title?: string, phrase?: string}>  $items
     */
    public function addVocabularyPhrases(
        array $items,
        int $siteId,
        ?int $sourceArticleId = null,
        ?int $actorId = null,
    ): PlanningDraftIntakeResult {
        unset($sourceArticleId);

        if (! SeoAccessControl::canMutateInSeoPanel()) {
            return new PlanningDraftIntakeResult(
                PlanningDraftIntakeResult::STATUS_FAILED,
                null,
                message: __('seo-content-ai::filament.articles_optimal.assign_failed'),
            );
        }

        if ($siteId <= 0 || ! SeoAccessControl::canAccessSite($siteId)) {
            return new PlanningDraftIntakeResult(
                PlanningDraftIntakeResult::STATUS_FAILED,
                null,
                message: __('seo-content-ai::filament.articles_optimal.assign_failed'),
            );
        }

        $phrases = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $trimmed = trim($item);
                if ($trimmed !== '') {
                    $phrases[] = $trimmed;
                }
                continue;
            }
            if (! is_array($item)) {
                continue;
            }
            $phrase = trim((string) ($item['keyword'] ?? $item['title'] ?? $item['phrase'] ?? ''));
            if ($phrase !== '') {
                $phrases[] = $phrase;
            }
        }

        if ($phrases === []) {
            return new PlanningDraftIntakeResult(
                PlanningDraftIntakeResult::STATUS_FAILED,
                null,
                message: __('seo-content-ai::filament.articles_optimal.assign_failed'),
            );
        }

        $actorId = $actorId !== null && $actorId > 0
            ? $actorId
            : (auth()->id() !== null ? (int) auth()->id() : null);

        try {
            $draft = $this->ensureSharedDraft($actorId, $siteId);
        } catch (Throwable $e) {
            return new PlanningDraftIntakeResult(
                PlanningDraftIntakeResult::STATUS_DRAFT_NOT_RESOLVED,
                null,
                message: $e->getMessage(),
            );
        }

        $draftId = (int) $draft->getKey();
        $summary = $this->keywordAssignment->assignPhrases($phrases, $draftId, $siteId, false, true);

        $summary = $this->normalizeDraftSummary($summary);

        return PlanningDraftIntakeResult::fromAssignmentSummary(
            $summary,
            $draftId,
            (string) __('seo-content-ai::filament.keyword.add_to_draft_completed'),
            (string) __('seo-content-ai::filament.article_list.already_in_draft'),
            $this->buildDraftSummaryMessage($summary),
        );
    }

    public function addPendingLink(
        int $articleId,
        string $anchorPhrase,
        ?int $actorId = null,
    ): PlanningDraftIntakeResult {
        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle) {
            return new PlanningDraftIntakeResult(
                PlanningDraftIntakeResult::STATUS_FAILED,
                null,
                message: __('seo-content-ai::filament.keyword.workspace_article_not_found'),
            );
        }

        $siteId = (int) ($article->site_id ?? 0);
        if ($siteId <= 0) {
            return new PlanningDraftIntakeResult(
                PlanningDraftIntakeResult::STATUS_FAILED,
                null,
                message: __('seo-content-ai::filament.article_edit.pending_link_missing_site'),
            );
        }

        $actorId = $actorId !== null && $actorId > 0
            ? $actorId
            : (auth()->id() !== null ? (int) auth()->id() : null);

        try {
            $draft = $this->ensureSharedDraft($actorId, $siteId);
        } catch (Throwable $e) {
            return new PlanningDraftIntakeResult(
                PlanningDraftIntakeResult::STATUS_DRAFT_NOT_RESOLVED,
                null,
                message: $e->getMessage(),
            );
        }

        $draftId = (int) $draft->getKey();
        $result = $this->pendingLinks->assignFromEditor($article, $anchorPhrase, $draftId);

        if (! ($result['success'] ?? false)) {
            return new PlanningDraftIntakeResult(
                PlanningDraftIntakeResult::STATUS_FAILED,
                $draftId,
                message: (string) ($result['message'] ?? __('seo-content-ai::filament.articles_optimal.assign_failed')),
                articleIds: [$articleId],
            );
        }

        $assigned = (bool) ($result['assigned_to_project'] ?? false);
        $alreadyTarget = (bool) ($result['already_has_target'] ?? false);

        if ($alreadyTarget) {
            return new PlanningDraftIntakeResult(
                PlanningDraftIntakeResult::STATUS_ADDED,
                $draftId,
                message: (string) ($result['message'] ?? ''),
                articleIds: [$articleId],
            );
        }

        return new PlanningDraftIntakeResult(
            $assigned ? PlanningDraftIntakeResult::STATUS_ADDED : PlanningDraftIntakeResult::STATUS_ALREADY_IN_DRAFT,
            $draftId,
            summary: ['added' => $assigned ? 1 : 0, 'duplicate' => $assigned ? 0 : 1],
            message: (string) ($result['message'] ?? __('seo-content-ai::filament.article_list.add_to_draft_completed')),
            articleIds: [$articleId],
            keywordIds: isset($result['keyword_id']) ? [(int) $result['keyword_id']] : [],
        );
    }

    /**
     * Strip legacy Assign counters that are meaningless for Shared Draft.
     *
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    public function normalizeDraftSummary(array $summary): array
    {
        $summary['domain_mismatch'] = 0;
        $summary['overflow'] = 0;
        // Treat "already in another project" as already_in_draft for planning pool UX.
        $already = (int) ($summary['already_in_project'] ?? 0);
        if ($already > 0) {
            $summary['duplicate'] = (int) ($summary['duplicate'] ?? 0) + $already;
            $summary['already_in_project'] = 0;
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function buildDraftSummaryMessage(array $summary): string
    {
        $added = (int) ($summary['added'] ?? 0);
        $duplicate = (int) ($summary['duplicate'] ?? 0);
        $siteNotResolved = (int) ($summary['site_not_resolved'] ?? 0);

        if ($siteNotResolved > 0 && $added === 0) {
            return (string) __('seo-content-ai::filament.article_list.site_not_resolved');
        }

        if ($added > 0 && $duplicate === 0) {
            return (string) __('seo-content-ai::filament.article_list.add_to_draft_completed_body', [
                'added' => $added,
            ]);
        }

        if ($added === 0 && $duplicate > 0) {
            return (string) __('seo-content-ai::filament.article_list.already_in_draft');
        }

        return (string) __('seo-content-ai::filament.article_list.add_to_draft_summary_body', [
            'added' => $added,
            'duplicate' => $duplicate,
        ]);
    }

    /**
     * Downgrade claimed "added" when Draft tasks are missing in DB.
     *
     * @param  array<string, mixed>  $summary
     * @param  list<int>  $articleIds
     * @return array<string, mixed>
     */
    private function confirmPersistedArticleAdds(array $summary, int $draftId, array $articleIds): array
    {
        $claimed = (int) ($summary['added'] ?? 0);
        if ($claimed <= 0 || $draftId <= 0 || $articleIds === []) {
            return $summary;
        }

        $persisted = SeoProjectTask::query()
            ->where('project_id', $draftId)
            ->whereIn('article_id', $articleIds)
            ->count();

        if ($persisted >= $claimed) {
            $summary['confirmed_task_count'] = $persisted;

            return $summary;
        }

        Log::warning('content_project.planning_draft.intake_persist_mismatch', [
            'draft_id' => $draftId,
            'claimed_added' => $claimed,
            'persisted' => $persisted,
            'article_ids' => $articleIds,
        ]);

        $summary['added'] = $persisted;
        $summary['persist_mismatch'] = $claimed - $persisted;
        $summary['confirmed_task_count'] = $persisted;

        return $summary;
    }

    /**
     * @param  list<int>  $articleIds
     * @param  list<int>  $keywordIds
     */
    private function logIntakeOutcome(
        string $sourceType,
        PlanningDraftIntakeResult $result,
        int $siteId,
        array $articleIds = [],
        array $keywordIds = [],
    ): void {
        $taskId = null;
        if ($result->draftProjectId !== null && $result->draftProjectId > 0 && $articleIds !== []) {
            $taskId = SeoProjectTask::query()
                ->where('project_id', $result->draftProjectId)
                ->whereIn('article_id', $articleIds)
                ->orderByDesc('id')
                ->value('id');
        }

        Log::info('content_project.planning_draft.intake', [
            'source_type' => $sourceType,
            'source_id' => $articleIds[0] ?? $keywordIds[0] ?? null,
            'source_site_id' => $siteId > 0 ? $siteId : null,
            'site_id' => $siteId > 0 ? $siteId : null,
            'draft_id' => $result->draftProjectId,
            'keyword_id' => $keywordIds[0] ?? null,
            'article_id' => $articleIds[0] ?? null,
            'draft_item_id' => $taskId !== null ? (int) $taskId : null,
            'result_status' => $result->status,
            'outcome' => $result->status,
            'failure_stage' => $result->isSuccess() ? null : $result->status,
            'summary' => [
                'added' => (int) ($result->summary['added'] ?? 0),
                'duplicate' => (int) ($result->summary['duplicate'] ?? 0),
            ],
        ]);
    }
}
