<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager;

use App\Models\Site;
use Illuminate\Support\Str;
use Omnichannel\Addons\Content\Enums\ArticleReviewStatus;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectPlannerRunDetail;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\GenerateNewContentSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteTargetAllocator;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\DiscoverNewTopicsDuplicateFilter;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftResolver;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentGenerationBatchPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionOptions;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\TopicGroup\TopicGroupRetrievalClient;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Throwable;

/**
 * Distribute selected Idea Manager note_items into the destination site Idea workspace.
 *
 * Reusable Idea contract (copy, never consume):
 * - Destination = seo_content_project_idea_workspaces (target site_id)
 * - Source warehouse Ideas are long-lived; Confirm never deletes/hides/marks-used source rows
 * - Distribution = COPY into destination workspace only (never move)
 * - Destination edits stay on destination; never write back to source
 * - Destination uniqueness: IdeaWorkspaceItemOps::findDuplicate (same ref OR normalized name)
 *   → intentional re-submit to same dest = already_present (idempotent); not infinite clones
 * - Semantic Keyword check is destination-advisory only (ideas + draft planning + published titles + Topic Core)
 * - Semantic/exact warnings NEVER block Confirm; Unknown = no fabricated score
 * - Does NOT Generate Ideas / create Project Items / Publish / mutate source workspace
 * - Active Month = intended planning context only (not stamped on tasks)
 */
final class IdeaManagerDistributionService
{
    public const STATUS_DISTRIBUTED = 'distributed';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ALREADY = 'already_present';

    public const CHECK_ANALYZED = 'analyzed';

    public const CHECK_UNAVAILABLE = 'unavailable';

    public const CHECK_PENDING = 'pending';

    public const CHECK_NO_FOCUS_KEYWORDS = 'no_focus_keywords';

    public function __construct(
        private readonly IdeaWorkspacePersistenceService $workspaces,
        private readonly DiscoverNewTopicsDuplicateFilter $topicDupFilter,
        private readonly ?TopicGroupRetrievalClient $topicGroupMatches = null,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $selectedItems
     * @return array<string, mixed>
     */
    public function preview(int $targetSiteId, array $selectedItems, string $activeMonth): array
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);
        abort_unless($targetSiteId > 0 && SeoAccessControl::canAccessSite($targetSiteId), 403);

        $month = ContentProjectMonthContext::normalize($activeMonth);
        $domain = (string) (Site::query()->whereKey($targetSiteId)->value('domain') ?? ('#'.$targetSiteId));
        $selected = $this->normalizeSelected($selectedItems);
        $focusInventory = $this->loadDestinationArticleFocusKeywords($targetSiteId);
        $exactMap = $this->indexFocusKeywordsByNormalizedKey($focusInventory);

        $rows = [];
        $exactCount = 0;
        $warnCount = 0;
        $unknownCount = 0;
        foreach ($selected as $item) {
            $row = $this->analyzeItem($item, $exactMap, $focusInventory, $targetSiteId);
            if ($row['exact_match']) {
                $exactCount++;
            }
            if (! empty($row['warning']) && empty($row['exact_match'])) {
                $warnCount++;
            }
            if (($row['check_status'] ?? '') === self::CHECK_UNAVAILABLE) {
                $unknownCount++;
            }
            $rows[] = $row;
        }

        return [
            'target_site_id' => $targetSiteId,
            'target_domain' => $domain,
            'active_month' => $month,
            'eligible_count' => count($rows),
            'duplicate_count' => $exactCount,
            'warning_count' => $warnCount,
            'unknown_count' => $unknownCount,
            'has_focus_keywords' => $focusInventory !== [],
            'focus_keyword_count' => count($focusInventory),
            // Confirm eligibility is input/auth based — warnings never disable.
            'can_confirm' => count($rows) > 0,
            'items' => $rows,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $selectedItems
     * @return array<string, mixed>
     */
    public function confirm(
        int $sourceSiteId,
        int $targetSiteId,
        array $selectedItems,
        string $activeMonth,
        ?int $actorId = null,
    ): array {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);
        abort_unless($targetSiteId > 0 && SeoAccessControl::canAccessSite($targetSiteId), 403);
        abort_unless($sourceSiteId > 0 && SeoAccessControl::canAccessSite($sourceSiteId), 403);

        $month = ContentProjectMonthContext::normalize($activeMonth);
        $domain = (string) (Site::query()->whereKey($targetSiteId)->value('domain') ?? ('#'.$targetSiteId));
        $empty = static fn (string $message, int $failed = 0): array => [
            'ok' => false,
            'target_site_id' => $targetSiteId,
            'target_domain' => $domain,
            'active_month' => $month,
            'distributed' => 0,
            'duplicate' => 0,
            'failed' => $failed,
            'already_present' => 0,
            'results' => [],
            'message' => $message,
        ];

        if ($sourceSiteId === $targetSiteId) {
            return $empty('source_equals_target', count($selectedItems));
        }

        $selected = $this->normalizeSelected($selectedItems);
        if ($selected === []) {
            return $empty('empty_selection');
        }

        // Exact collisions become already_present per-Idea — never abort the whole confirm.
        return $this->mergeIntoDestination($targetSiteId, $domain, $month, $selected, $actorId);
    }

    /**
     * Action B: Distribute selected Ideas into destination Site + Active Month via
     * canonical SEO Audit Generate Ideas workflow (GenerateNewContentSuggestionsCommand).
     * Source Ideas remain reusable and are NEVER consumed/deleted.
     *
     * @param  list<array<string, mixed>>  $selectedItems
     * @return array<string, mixed>
     */
    public function distributeWithAi(
        int $targetSiteId,
        array $selectedItems,
        string $activeMonth,
        string $requestKey = '',
        ?int $actorId = null,
    ): array {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);
        abort_unless($targetSiteId > 0 && SeoAccessControl::canAccessSite($targetSiteId), 403);

        $month = ContentProjectMonthContext::normalize($activeMonth);
        $site = Site::query()->find($targetSiteId);
        $domain = (string) ($site?->domain ?? ('#'.$targetSiteId));
        $fail = static fn (string $code, string $message): array => [
            'ok' => false,
            'status' => 'failed',
            'code' => $code,
            'message' => $message,
            'target_site_id' => $targetSiteId,
            'target_domain' => $domain,
            'active_month' => $month,
            'planner_run_id' => 0,
            'run_detail_url' => null,
            'already_active' => false,
        ];

        if (! $site instanceof Site) {
            return $fail('invalid_target_site', 'Không tìm thấy domain đích.');
        }

        $selected = $this->normalizeSelected($selectedItems);
        if ($selected === []) {
            return $fail('empty_selection', 'Chưa chọn Idea nào để phân phối.');
        }

        $draft = app(PlanningDraftResolver::class)->findCanonicalSharedDraft();
        if (! $draft instanceof SeoProject || ! $draft->isDraftPlanning()) {
            return $fail('missing_shared_draft', 'Chưa có Shared Planning Draft đang hoạt động.');
        }

        $requestedFromTargets = AuditNoteDnaNormalizer::totalTargetDnaCount($selected);
        if ($requestedFromTargets <= 0) {
            return $fail('empty_targets', 'Số lượng DNA mục tiêu phải lớn hơn 0.');
        }

        $batchPolicy = new NewContentGenerationBatchPolicy;
        if ($batchPolicy->exceedsHardCeiling($requestedFromTargets)) {
            return $fail('batch_too_large', (string) __('seo-content-ai::filament.projects.planner_plan_too_large'));
        }

        $allocation = AuditNoteTargetAllocator::apply($selected, $requestedFromTargets);
        if ($allocation['code'] === AuditNoteTargetAllocator::CODE_TOO_MANY_TOPICS) {
            return $fail('too_many_topics', (string) __('seo-content-ai::filament.projects.audit_notes_too_many_topics', [
                'topics' => $allocation['topic_count'],
                'quantity' => $allocation['requested_quantity'],
            ]));
        }

        $quantity = max(1, (int) $allocation['total_target']);
        $options = NewContentSuggestionOptions::normalize([
            'quantity' => $quantity,
            'note_items' => $allocation['items'],
            'planning_month' => $month,
            'direction' => NewContentSuggestionOptions::DIRECTION_AUTOMATIC,
            'post_type' => NewContentSuggestionOptions::CONTENT_TYPE_POST,
            'use_site_context' => true,
            'use_keyword_intelligence' => true,
            'use_mcp_context' => true,
        ]);

        $actorId = $actorId !== null && $actorId > 0
            ? $actorId
            : (auth()->id() !== null ? (int) auth()->id() : null);

        $safeKey = trim($requestKey) !== '' ? trim($requestKey) : Str::uuid()->toString();
        $idemKey = 'idea-manager-ai:'.$draft->getKey().':'.$targetSiteId.':'.$month.':'.$safeKey;

        try {
            $result = app(ContentProjectCommandBus::class)->dispatch(
                new GenerateNewContentSuggestionsCommand(
                    (int) $draft->getKey(),
                    $targetSiteId,
                    $quantity,
                    $options,
                ),
                ActorContext::user($actorId, $targetSiteId, $idemKey),
            );
        } catch (Throwable $e) {
            return $fail('dispatch_exception', $e->getMessage() ?: 'Không thể khởi chạy AI tạo gợi ý.');
        }

        if (! $result->success) {
            return $fail($result->code ?: 'dispatch_failed', $result->message ?: 'Không thể khởi chạy AI tạo gợi ý.');
        }

        $runId = (int) ($result->metadata['planner_run_id'] ?? 0);
        $alreadyActive = (bool) ($result->metadata['already_active'] ?? false);
        $status = (string) ($result->metadata['status'] ?? SeoContentProjectPlannerRun::STATUS_QUEUED);

        return [
            'ok' => $runId > 0,
            'status' => $status,
            'code' => $result->code,
            'message' => $result->message,
            'target_site_id' => $targetSiteId,
            'target_domain' => $domain,
            'active_month' => $month,
            'planner_run_id' => $runId,
            'requested' => (int) ($result->metadata['requested'] ?? $quantity),
            'already_active' => $alreadyActive,
            'run_detail_url' => $this->resolveRunDetailUrl($runId),
        ];
    }

    /**
     * Poll canonical SeoContentProjectPlannerRun status for UI progress / completion.
     *
     * @return array<string, mixed>
     */
    public function pollRun(int $runId): array
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);
        if ($runId <= 0) {
            return ['found' => false, 'status' => 'failed', 'terminal' => true, 'error' => 'Invalid run id.'];
        }

        $run = SeoContentProjectPlannerRun::query()->find($runId);
        if (! $run instanceof SeoContentProjectPlannerRun) {
            return ['found' => false, 'status' => 'failed', 'terminal' => true, 'error' => 'Run not found.'];
        }

        $summary = is_array($run->result_summary) ? $run->result_summary : [];
        $status = (string) ($summary['status'] ?? SeoContentProjectPlannerRun::STATUS_QUEUED);
        $addedIds = is_array($summary['added_task_ids'] ?? null) ? $summary['added_task_ids'] : [];
        $added = (int) ($summary['added'] ?? count($addedIds));
        $skipped = (int) ($summary['duplicate_skipped'] ?? 0);
        $error = isset($summary['error']) && is_string($summary['error']) ? $summary['error'] : null;

        $terminal = in_array($status, [
            SeoContentProjectPlannerRun::STATUS_COMPLETED,
            SeoContentProjectPlannerRun::STATUS_PARTIAL,
            SeoContentProjectPlannerRun::STATUS_FAILED,
            SeoContentProjectPlannerRun::STATUS_CANCELLED,
        ], true);

        return [
            'found' => true,
            'planner_run_id' => $runId,
            'status' => $status,
            'terminal' => $terminal,
            'ok' => in_array($status, [
                SeoContentProjectPlannerRun::STATUS_COMPLETED,
                SeoContentProjectPlannerRun::STATUS_PARTIAL,
            ], true),
            'added' => $added,
            'duplicate_skipped' => $skipped,
            'error' => $error,
            'run_detail_url' => $this->resolveRunDetailUrl($runId),
        ];
    }

    /**
     * Reconcile latest run for $targetSiteId after network timeout before allowing retry.
     *
     * @return array<string, mixed>|null
     */
    public function reconcileLatestRun(int $targetSiteId): ?array
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);
        if ($targetSiteId <= 0 || ! SeoAccessControl::canAccessSite($targetSiteId)) {
            return null;
        }

        $draft = app(PlanningDraftResolver::class)->findCanonicalSharedDraft();
        if (! $draft instanceof SeoProject) {
            return null;
        }

        $run = SeoContentProjectPlannerRun::query()
            ->where('project_id', (int) $draft->getKey())
            ->where('source', SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT)
            ->orderByDesc('id')
            ->first();

        if (! $run instanceof SeoContentProjectPlannerRun) {
            return null;
        }

        return $this->pollRun((int) $run->getKey());
    }

    private function resolveRunDetailUrl(int $runId): ?string
    {
        if ($runId <= 0) {
            return null;
        }

        try {
            $url = ContentProjectPlannerRunDetail::getUrl(['run' => $runId], isAbsolute: false);

            return is_string($url) && $url !== '' ? $url : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $selected
     * @return array<string, mixed>
     */
    private function mergeIntoDestination(
        int $targetSiteId,
        string $domain,
        string $month,
        array $selected,
        ?int $actorId,
    ): array {
        $attempt = function (array $destItems, int $revision) use ($selected): array {
            $merged = $destItems;
            $results = [];
            $toAdd = [];
            foreach ($selected as $item) {
                $ref = (string) $item['cluster_ref'];
                $name = (string) $item['cluster_name_snapshot'];
                $existing = IdeaWorkspaceItemOps::findDuplicate($merged, $ref, $name);
                if ($existing !== null) {
                    $results[] = [
                        'cluster_ref' => $ref,
                        'name' => $name,
                        'status' => self::STATUS_ALREADY,
                        'reason' => 'already_in_destination_workspace',
                        'matched' => (string) ($existing['cluster_name_snapshot'] ?? $existing['cluster_ref'] ?? ''),
                    ];
                    continue;
                }
                $normalized = AuditNoteDnaNormalizer::normalizeNoteItem($item);
                if ($normalized === null) {
                    $results[] = [
                        'cluster_ref' => $ref,
                        'name' => $name,
                        'status' => self::STATUS_FAILED,
                        'reason' => 'normalize_failed',
                        'matched' => null,
                    ];
                    continue;
                }
                $toAdd[] = $normalized;
                $merged[] = $normalized;
                $results[] = [
                    'cluster_ref' => (string) $normalized['cluster_ref'],
                    'name' => (string) $normalized['cluster_name_snapshot'],
                    'status' => self::STATUS_DISTRIBUTED,
                    'reason' => null,
                    'matched' => null,
                ];
            }

            return ['merged' => $merged, 'results' => $results, 'to_add' => $toAdd, 'revision' => $revision];
        };

        $destSnap = $this->workspaces->load($targetSiteId, $actorId);
        $plan = $attempt(
            is_array($destSnap['note_items'] ?? null) ? $destSnap['note_items'] : [],
            (int) ($destSnap['revision'] ?? 0),
        );

        $saveStatus = IdeaWorkspacePersistenceService::STATUS_NOOP;
        if ($plan['to_add'] !== []) {
            $save = $this->workspaces->save($targetSiteId, $plan['merged'], $plan['revision'], $actorId);
            $saveStatus = (string) ($save['status'] ?? 'error');
            if ($saveStatus === IdeaWorkspacePersistenceService::STATUS_CONFLICT) {
                $destSnap = $this->workspaces->load($targetSiteId, $actorId);
                $plan = $attempt(
                    is_array($destSnap['note_items'] ?? null) ? $destSnap['note_items'] : [],
                    (int) ($destSnap['revision'] ?? 0),
                );
                if ($plan['to_add'] !== []) {
                    $save = $this->workspaces->save($targetSiteId, $plan['merged'], $plan['revision'], $actorId);
                    $saveStatus = (string) ($save['status'] ?? 'error');
                } else {
                    $saveStatus = IdeaWorkspacePersistenceService::STATUS_NOOP;
                }
            }
        }

        $okStatuses = [
            IdeaWorkspacePersistenceService::STATUS_OK,
            IdeaWorkspacePersistenceService::STATUS_NOOP,
        ];
        $ok = in_array($saveStatus, $okStatuses, true);
        $results = $plan['results'];

        if (! $ok) {
            foreach ($results as &$row) {
                if ($row['status'] === self::STATUS_DISTRIBUTED) {
                    $row['status'] = self::STATUS_FAILED;
                    $row['reason'] = 'destination_save_'.$saveStatus;
                }
            }
            unset($row);
        }

        $count = static fn (string $status): int => count(array_filter(
            $results,
            static fn (array $r): bool => $r['status'] === $status,
        ));

        return [
            'ok' => $ok && $count(self::STATUS_FAILED) === 0,
            'target_site_id' => $targetSiteId,
            'target_domain' => $domain,
            'active_month' => $month,
            'distributed' => $ok ? $count(self::STATUS_DISTRIBUTED) : 0,
            'duplicate' => 0,
            'failed' => $count(self::STATUS_FAILED),
            'already_present' => $count(self::STATUS_ALREADY),
            'results' => $results,
            // Explicit reusable-Idea guarantee for clients.
            'source_unchanged' => true,
            'source_consumed' => false,
            'message' => $ok ? ($plan['to_add'] === [] ? 'idempotent_noop' : null) : ('destination_save_'.$saveStatus),
        ];
    }

    /**
     * Exact + Semantic Keyword check against destination Article Focus Keywords ONLY.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, array<string, mixed>>  $exactMap
     * @param  list<array<string, mixed>>  $focusInventory
     * @return array<string, mixed>
     */
    private function analyzeItem(
        array $item,
        array $exactMap,
        array $focusInventory,
        int $targetSiteId,
    ): array {
        $ref = (string) ($item['cluster_ref'] ?? '');
        $name = (string) ($item['cluster_name_snapshot'] ?? '');
        $base = [
            'cluster_ref' => $ref,
            'name' => $name,
            'duplicate' => false,
            'exact_match' => false,
            'warning' => false,
            'check_status' => self::CHECK_ANALYZED,
            'similarity_score' => null,
            'matched' => null,
            'matched_type' => null,
            'focus_keyword' => null,
            'article_id' => null,
            'article_title' => null,
            'article_status' => null,
            'article_status_label' => null,
            'article_edit_url' => null,
            'reason' => null,
        ];

        if ($focusInventory === []) {
            return array_merge($base, [
                'check_status' => self::CHECK_NO_FOCUS_KEYWORDS,
                'reason' => 'no_focus_keywords',
            ]);
        }

        $key = $this->normalizePhraseKey($name);
        if ($key !== '' && isset($exactMap[$key])) {
            $hit = $exactMap[$key];

            return array_merge($base, [
                'exact_match' => true,
                'warning' => true,
                'matched' => (string) $hit['focus_keyword'],
                'matched_type' => 'focus_keyword',
                'focus_keyword' => (string) $hit['focus_keyword'],
                'article_id' => (int) $hit['article_id'],
                'article_title' => (string) $hit['article_title'],
                'article_status' => (string) $hit['article_status'],
                'article_status_label' => (string) $hit['article_status_label'],
                'article_edit_url' => $hit['article_edit_url'],
                'reason' => 'exact_focus_keyword',
            ]);
        }

        $client = $this->topicGroupMatches;
        if (! $client instanceof TopicGroupRetrievalClient) {
            try {
                $client = app(TopicGroupRetrievalClient::class);
            } catch (Throwable) {
                $client = null;
            }
        }
        if (! $client instanceof TopicGroupRetrievalClient) {
            return array_merge($base, [
                'check_status' => self::CHECK_UNAVAILABLE,
                // Unknown is not a similarity warning — never fabricate a score.
                'warning' => false,
                'similarity_score' => null,
                'reason' => 'semantic_client_unavailable',
            ]);
        }

        try {
            $groups = array_map(static fn (array $g): array => [
                'ref' => $g['ref'],
                'label' => $g['label'],
                'examples' => $g['examples'],
            ], array_slice($focusInventory, 0, 80));
            $result = $client->match('site:'.$targetSiteId, $name, $groups, null, 3);
            $best = $result->matches[0] ?? null;
            if ($best === null) {
                return array_merge($base, [
                    'check_status' => self::CHECK_ANALYZED,
                    'reason' => 'no_semantic_match',
                ]);
            }
            $matchedRef = (string) $best['ref'];
            $score = isset($best['score']) ? (float) $best['score'] : null;
            $meta = null;
            foreach ($focusInventory as $g) {
                if ($g['ref'] === $matchedRef) {
                    $meta = $g;
                    break;
                }
            }
            if ($meta === null) {
                return array_merge($base, [
                    'check_status' => self::CHECK_ANALYZED,
                    'reason' => 'no_semantic_match',
                ]);
            }

            // TopicGroupRetrievalClient already applies provider min_score — returned hits are advisory.
            // Never treat semantic score as exact_match or Confirm blocker.
            return array_merge($base, [
                'check_status' => self::CHECK_ANALYZED,
                'warning' => true,
                'similarity_score' => $score,
                'matched' => (string) $meta['focus_keyword'],
                'matched_type' => 'focus_keyword',
                'focus_keyword' => (string) $meta['focus_keyword'],
                'article_id' => (int) $meta['article_id'],
                'article_title' => (string) $meta['article_title'],
                'article_status' => (string) $meta['article_status'],
                'article_status_label' => (string) $meta['article_status_label'],
                'article_edit_url' => $meta['article_edit_url'],
                'reason' => 'semantic_similarity',
            ]);
        } catch (Throwable) {
            return array_merge($base, [
                'check_status' => self::CHECK_UNAVAILABLE,
                'warning' => false,
                'similarity_score' => null,
                'reason' => 'semantic_service_unavailable',
            ]);
        }
    }

    /**
     * Canonical destination inventory: ONLY Focus Keywords of Articles belonging to $targetSiteId.
     * Never includes Idea Workspace, Keyword Dictionary, Topic Core, Shared Draft, or other domains.
     *
     * @return list<array<string, mixed>>
     */
    private function loadDestinationArticleFocusKeywords(int $targetSiteId): array
    {
        if ($targetSiteId <= 0) {
            return [];
        }

        try {
            $articles = SeoArticle::query()
                ->where('site_id', $targetSiteId)
                ->where(static function ($q): void {
                    $q->whereNull('review_status')
                        ->orWhere('review_status', '!=', ArticleReviewStatus::Archived->value);
                })
                ->with([
                    'articleMetas' => static function ($q): void {
                        $q->whereIn('meta_key', ['seo_focus_keyword', 'rank_math_focus_keyword']);
                    },
                    'wordpressLink:id,article_id,wp_post_id,observed_post_status',
                ])
                ->orderByDesc('id')
                ->get(['id', 'site_id', 'title', 'review_status']);
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($articles as $article) {
            if (! $article instanceof SeoArticle) {
                continue;
            }
            $focusKeyword = $this->extractArticleFocusKeyword($article);
            if ($focusKeyword === '') {
                continue;
            }
            $normKey = $this->normalizePhraseKey($focusKeyword);
            if ($normKey === '') {
                continue;
            }
            $articleId = (int) $article->getKey();
            [$statusCode, $statusLabel] = $this->resolveArticleStatus($article);

            $out[] = [
                'ref' => 'article_fk:'.$articleId,
                'label' => $focusKeyword,
                'examples' => [$focusKeyword],
                'focus_keyword' => $focusKeyword,
                'normalized_key' => $normKey,
                'article_id' => $articleId,
                'article_title' => trim((string) ($article->title ?? '')) ?: ('Article #'.$articleId),
                'article_status' => $statusCode,
                'article_status_label' => $statusLabel,
                'article_edit_url' => $this->resolveArticleEditUrl($articleId),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $inventory
     * @return array<string, array<string, mixed>>
     */
    private function indexFocusKeywordsByNormalizedKey(array $inventory): array
    {
        $map = [];
        foreach ($inventory as $row) {
            $key = (string) ($row['normalized_key'] ?? '');
            if ($key !== '' && ! isset($map[$key])) {
                $map[$key] = $row;
            }
        }

        return $map;
    }

    private function extractArticleFocusKeyword(SeoArticle $article): string
    {
        $metas = $article->relationLoaded('articleMetas') ? $article->articleMetas : collect();
        $primary = trim((string) ($metas->firstWhere('meta_key', 'seo_focus_keyword')?->meta_value ?? ''));
        if ($primary !== '') {
            return $primary;
        }

        return trim((string) ($metas->firstWhere('meta_key', 'rank_math_focus_keyword')?->meta_value ?? ''));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveArticleStatus(SeoArticle $article): array
    {
        $wpStatus = strtolower(trim((string) ($article->wordpressLink?->observed_post_status ?? '')));
        if ($wpStatus === 'publish') {
            return ['published', 'Published'];
        }

        $review = strtolower(trim((string) ($article->review_status ?? '')));
        if ($review === ArticleReviewStatus::Approved->value) {
            return ['approved', 'Approved'];
        }
        if ($review === ArticleReviewStatus::PendingReview->value) {
            return ['pending_review', 'Review'];
        }

        return ['draft', 'Draft'];
    }

    private function resolveArticleEditUrl(int $articleId): ?string
    {
        if ($articleId <= 0) {
            return null;
        }

        try {
            $url = ArticleResource::getUrl('edit', ['record' => $articleId], isAbsolute: false);

            return is_string($url) && $url !== '' ? $url : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizePhraseKey(string $phrase): string
    {
        $seed = AuditNoteDnaNormalizer::normalizeSeedText($phrase);

        return AuditNoteDnaNormalizer::normalizeKey($seed !== '' ? $seed : $phrase);
    }

    /**
     * @param  list<array<string, mixed>>  $selectedItems
     * @return list<array<string, mixed>>
     */
    private function normalizeSelected(array $selectedItems): array
    {
        $out = [];
        foreach ($selectedItems as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            if (! isset($raw['cluster_name_snapshot']) && isset($raw['name'])) {
                $raw['cluster_name_snapshot'] = $raw['name'];
            }
            $item = AuditNoteDnaNormalizer::normalizeNoteItem($raw);
            if ($item !== null) {
                $out[] = $item;
                continue;
            }
            $ref = trim((string) ($raw['cluster_ref'] ?? ''));
            $name = trim((string) ($raw['cluster_name_snapshot'] ?? $raw['name'] ?? ''));
            if ($ref === '' || $name === '') {
                continue;
            }
            $out[] = [
                'source_type' => AuditNoteDnaNormalizer::SOURCE_TYPE_CLUSTER,
                'cluster_ref' => $ref,
                'cluster_name_snapshot' => $name,
                'seed_text' => null,
                'mcp_share_snapshot' => null,
                'target_dna_count' => AuditNoteDnaNormalizer::DEFAULT_TARGET_DNA_COUNT,
                'target_mode' => 'manual',
                'dna' => [],
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>  $names
     * @return array<string, string>
     */
    public function topicDuplicateMap(int $siteId, array $names): array
    {
        $candidates = [];
        foreach (array_values($names) as $i => $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $candidates[] = [
                'candidate_key' => 'im-'.$i,
                'name' => $name,
                'target_dna_count' => 1,
                'dna' => [],
            ];
        }
        if ($candidates === []) {
            return [];
        }

        try {
            $result = $this->topicDupFilter->filter($siteId, $candidates);
        } catch (Throwable) {
            return [];
        }

        $map = [];
        foreach ($result['rejected'] as $row) {
            $cand = is_array($row['candidate'] ?? null) ? $row['candidate'] : [];
            $name = trim((string) ($cand['name'] ?? ''));
            $key = AuditNoteDnaNormalizer::normalizeKey(
                AuditNoteDnaNormalizer::normalizeSeedText($name) ?: $name
            );
            if ($key !== '') {
                $map[$key] = $name;
            }
        }

        return $map;
    }
}
