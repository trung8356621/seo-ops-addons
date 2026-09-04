<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Enums\SeoProjectTaskEventType;
use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTaskEvent;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptResultLink;
use App\Support\RuntimeLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical Content Project ↔ local SeoArticle association reconciliation.
 *
 * Applies to CREATE / REWRITE / IMPROVE when:
 * - article_id is null/0, or
 * - article_id > 0 but local articles.id does not exist (stale / WP / external id).
 *
 * Priority (strongest first), no fuzzy title LIKE:
 * 1. automation_origin (seo_project_task + task id)
 * 2. run_item.article_id + output_snapshot.article_id
 * 3. article_meta content_project_run.task_id
 * 4. prompt_result_links.project_task_id → article_id
 * 5. task_events article.linked / article.created payload
 * 6. exact wp_post_id via wordpressLink + same site (incl. stale task.article_id as WP id)
 * 7. exact slug / wp_permalink + site_id
 * 8. exact title (=) + site_id — final fallback only
 *
 * Persist only when exactly one unambiguous candidate and not owned by another active task.
 */
final class ContentProjectExistingArticleReconciler
{
    /**
     * @param  Collection<int, SeoProjectTask>|list<SeoProjectTask>|null  $tasks
     * @return list<ContentProjectExistingArticleReconcileResult>
     */
    public function reconcileProjectMissingLinks(SeoProject $project, Collection|array|null $tasks = null): array
    {
        $projectId = (int) $project->getKey();
        $siteId = (int) ($project->site_id ?? 0);
        $resolvedSiteId = $siteId > 0 ? $siteId : null;

        if ($tasks === null) {
            $tasks = SeoProjectTask::query()
                ->where('project_id', $projectId)
                ->whereNull('archived_at')
                ->orderBy('id')
                ->get();
        }

        $results = [];
        foreach ($tasks as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }
            if (ContentProjectManualArticleResolution::requiresManualResolution($task)) {
                $results[] = new ContentProjectExistingArticleReconcileResult(
                    status: ContentProjectExistingArticleReconcileResult::STATUS_NOT_REQUIRED,
                    reason: ContentProjectManualArticleResolution::MANUAL_ACTION,
                );
                continue;
            }
            if (! $this->needsAssociationRepair($task, $resolvedSiteId)) {
                continue;
            }
            $results[] = $this->reconcileTask($task, $resolvedSiteId, persist: true);
        }

        return $results;
    }

    /**
     * True when article_id is empty or points at a non-local / wrong-site article.
     */
    public function needsAssociationRepair(SeoProjectTask $task, ?int $siteId = null): bool
    {
        $directId = (int) ($task->article_id ?? 0);
        if ($directId <= 0) {
            return true;
        }

        $resolvedSiteId = $this->resolveSiteId($task, $siteId);

        return LocalArticleAssociationGuard::resolveLocalArticleId($directId, $resolvedSiteId) === null;
    }

    /**
     * Full diagnostic dump for one task — used by tests / ops logging.
     *
     * @return array{
     *     task_id: int,
     *     task_article_id: int|null,
     *     site_id: int|null,
     *     candidates: list<array{article_id: int, matched_by: string}>,
     *     candidates_by_source: array<string, list<int>>,
     *     pick: array{status: string, article_id: ?int, matched_by: string, reason: string},
     *     ownership_conflict: string|null,
     *     result: array<string, mixed>
     * }
     */
    public function diagnose(SeoProjectTask $task, ?int $siteId = null, bool $persist = false): array
    {
        $taskId = (int) $task->getKey();
        $resolvedSiteId = $this->resolveSiteId($task, $siteId);
        $candidates = $this->collectCandidates($taskId, $resolvedSiteId, $task);
        $bySource = [];
        foreach ($candidates as $row) {
            $source = (string) ($row['matched_by'] ?? 'unknown');
            $bySource[$source] ??= [];
            $bySource[$source][] = (int) $row['article_id'];
        }
        $pick = $this->pickUnambiguousCandidate($candidates);
        $ownership = null;
        if (($pick['status'] ?? '') === 'ok' && $pick['article_id'] !== null) {
            $ownership = $this->ownershipConflict($taskId, (int) $pick['article_id']);
        }
        $result = $this->reconcileTask($task, $resolvedSiteId, persist: $persist);

        return [
            'task_id' => $taskId,
            'task_article_id' => (int) ($task->article_id ?? 0) > 0 ? (int) $task->article_id : null,
            'site_id' => $resolvedSiteId,
            'candidates' => $candidates,
            'candidates_by_source' => $bySource,
            'pick' => $pick,
            'ownership_conflict' => $ownership,
            'result' => $result->toArray(),
        ];
    }

    public function reconcileTask(
        SeoProjectTask $task,
        ?int $siteId = null,
        bool $persist = true,
    ): ContentProjectExistingArticleReconcileResult {
        $taskId = (int) $task->getKey();
        $resolvedSiteId = $this->resolveSiteId($task, $siteId);
        $type = SeoProjectTask::normalizeType($task->type);
        $requiresExisting = in_array($type, SeoProjectTask::typesRequiringExistingArticle(), true);

        if (ContentProjectManualArticleResolution::requiresManualResolution($task)) {
            return new ContentProjectExistingArticleReconcileResult(
                status: ContentProjectExistingArticleReconcileResult::STATUS_NOT_REQUIRED,
                reason: ContentProjectManualArticleResolution::MANUAL_ACTION,
                persisted: false,
            );
        }

        $directId = (int) ($task->article_id ?? 0);
        if (! $requiresExisting && $directId <= 0) {
            return new ContentProjectExistingArticleReconcileResult(
                status: ContentProjectExistingArticleReconcileResult::STATUS_NOT_REQUIRED,
                reason: 'create_unlinked_requires_manual_resolution',
                persisted: false,
            );
        }
        if ($directId > 0) {
            $localId = LocalArticleAssociationGuard::resolveLocalArticleId($directId, $resolvedSiteId);
            if ($localId !== null) {
                return new ContentProjectExistingArticleReconcileResult(
                    status: ContentProjectExistingArticleReconcileResult::STATUS_RESOLVED,
                    articleId: $localId,
                    matchedBy: 'task.article_id',
                    reason: 'Local article already linked.',
                );
            }
        }

        $candidate = $this->findUnambiguousCandidate($taskId, $resolvedSiteId, $task);
        if ($candidate['status'] !== 'ok' || $candidate['article_id'] === null) {
            if (! $requiresExisting && $directId <= 0 && ($candidate['status'] ?? '') === 'missing') {
                return new ContentProjectExistingArticleReconcileResult(
                    status: ContentProjectExistingArticleReconcileResult::STATUS_NOT_REQUIRED,
                    reason: 'Create item has no article association yet.',
                );
            }

            return new ContentProjectExistingArticleReconcileResult(
                status: $candidate['status'] === 'ambiguous'
                    ? ContentProjectExistingArticleReconcileResult::STATUS_AMBIGUOUS
                    : ContentProjectExistingArticleReconcileResult::STATUS_MISSING,
                articleId: null,
                matchedBy: (string) ($candidate['matched_by'] ?? ''),
                reason: (string) ($candidate['reason'] ?? 'No unambiguous local article candidate.'),
            );
        }

        $articleId = (int) $candidate['article_id'];
        $ownership = $this->ownershipConflict($taskId, $articleId);
        if ($ownership !== null) {
            return new ContentProjectExistingArticleReconcileResult(
                status: ContentProjectExistingArticleReconcileResult::STATUS_CONFLICT,
                articleId: $articleId,
                matchedBy: (string) ($candidate['matched_by'] ?? ''),
                reason: $ownership,
            );
        }

        if (! $persist) {
            return new ContentProjectExistingArticleReconcileResult(
                status: ContentProjectExistingArticleReconcileResult::STATUS_REPAIRED,
                articleId: $articleId,
                matchedBy: (string) ($candidate['matched_by'] ?? ''),
                reason: 'Candidate found — persist skipped.',
                persisted: false,
            );
        }

        $persisted = $this->persistAssociation($task, $articleId);
        if (! $persisted) {
            return new ContentProjectExistingArticleReconcileResult(
                status: ContentProjectExistingArticleReconcileResult::STATUS_CONFLICT,
                articleId: $articleId,
                matchedBy: (string) ($candidate['matched_by'] ?? ''),
                reason: 'Could not persist article association (unique ownership conflict).',
            );
        }

        RuntimeLogger::info('content_project.article_association_repaired', [
            'task_id' => $taskId,
            'article_id' => $articleId,
            'previous_article_id' => $directId > 0 ? $directId : null,
            'matched_by' => $candidate['matched_by'] ?? null,
            'site_id' => $resolvedSiteId,
            'task_type' => $type,
        ]);

        return new ContentProjectExistingArticleReconcileResult(
            status: ContentProjectExistingArticleReconcileResult::STATUS_REPAIRED,
            articleId: $articleId,
            matchedBy: (string) ($candidate['matched_by'] ?? ''),
            reason: 'Repaired Content Project ↔ local article association.',
            persisted: true,
        );
    }

    /**
     * Pure candidate pick from identity hints — unit-testable without DB.
     *
     * @param  list<array{article_id: int, matched_by: string}>  $candidates
     * @return array{status: 'ok'|'missing'|'ambiguous', article_id: ?int, matched_by: string, reason: string}
     */
    public function pickUnambiguousCandidate(array $candidates): array
    {
        $byId = [];
        $matchedBy = [];
        foreach ($candidates as $row) {
            $id = (int) ($row['article_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $byId[$id] = true;
            if (! isset($matchedBy[$id])) {
                $matchedBy[$id] = (string) ($row['matched_by'] ?? '');
            }
        }

        $ids = array_keys($byId);
        if ($ids === []) {
            return [
                'status' => 'missing',
                'article_id' => null,
                'matched_by' => '',
                'reason' => 'No candidate article identities.',
            ];
        }
        if (count($ids) > 1) {
            return [
                'status' => 'ambiguous',
                'article_id' => null,
                'matched_by' => '',
                'reason' => 'Multiple candidate articles — refuse to guess.',
            ];
        }

        $only = (int) $ids[0];

        return [
            'status' => 'ok',
            'article_id' => $only,
            'matched_by' => (string) ($matchedBy[$only] ?? ''),
            'reason' => 'Unambiguous candidate.',
        ];
    }

    /**
     * Exact title equality helper — never LIKE / fuzzy.
     *
     * @param  list<array{id: int, title: string}>  $articles
     * @return list<array{article_id: int, matched_by: string}>
     */
    public function matchExactTitleCandidates(string $title, array $articles): array
    {
        $needle = trim($title);
        if ($needle === '') {
            return [];
        }

        $out = [];
        foreach ($articles as $row) {
            $id = (int) ($row['id'] ?? 0);
            $candidateTitle = trim((string) ($row['title'] ?? ''));
            if ($id <= 0 || $candidateTitle === '') {
                continue;
            }
            if ($candidateTitle === $needle) {
                $out[] = ['article_id' => $id, 'matched_by' => 'exact.title'];
            }
        }

        return $out;
    }

    /**
     * @return array{status: 'ok'|'missing'|'ambiguous', article_id: ?int, matched_by: string, reason: string}
     */
    private function findUnambiguousCandidate(int $taskId, ?int $siteId, SeoProjectTask $task): array
    {
        return $this->pickUnambiguousCandidate($this->collectCandidates($taskId, $siteId, $task));
    }

    /**
     * @return list<array{article_id: int, matched_by: string}>
     */
    private function collectCandidates(int $taskId, ?int $siteId, SeoProjectTask $task): array
    {
        $ordered = [];

        foreach ($this->candidatesFromAutomationOrigin($taskId, $siteId) as $row) {
            $ordered[] = $row;
        }
        foreach ($this->candidatesFromRunItems($taskId, $siteId) as $row) {
            $ordered[] = $row;
        }
        foreach ($this->candidatesFromContentProjectRunMeta($taskId, $siteId) as $row) {
            $ordered[] = $row;
        }
        foreach ($this->candidatesFromPromptLinks($taskId, $siteId) as $row) {
            $ordered[] = $row;
        }
        foreach ($this->candidatesFromTaskEvents($taskId, $siteId) as $row) {
            $ordered[] = $row;
        }
        foreach ($this->candidatesFromStaleTaskArticleAsWpPostId($task, $siteId) as $row) {
            $ordered[] = $row;
        }

        $firstPass = $this->pickUnambiguousCandidate($ordered);
        if ($firstPass['status'] === 'ok' && $firstPass['article_id'] !== null) {
            return $ordered;
        }

        foreach ($this->candidatesFromExactSlugOrPermalink($task, $siteId) as $row) {
            $ordered[] = $row;
        }
        foreach ($this->candidatesFromExactTitle($task, $siteId) as $row) {
            $ordered[] = $row;
        }

        return $ordered;
    }

    /**
     * @return list<array{article_id: int, matched_by: string}>
     */
    private function candidatesFromAutomationOrigin(int $taskId, ?int $siteId): array
    {
        if ($taskId <= 0 || ! $this->tableExists('article_meta')) {
            return [];
        }

        $typeHits = SeoArticle::query()
            ->when($siteId !== null && $siteId > 0, static fn ($q) => $q->where('site_id', $siteId))
            ->whereHas('articleMetas', static function ($query): void {
                $query->where('meta_key', 'automation_origin_type')
                    ->where('meta_value', 'seo_project_task');
            })
            ->whereHas('articleMetas', static function ($query) use ($taskId): void {
                $query->where('meta_key', 'automation_origin_id')
                    ->where('meta_value', (string) $taskId);
            })
            ->orderByDesc('id')
            ->limit(5)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $out = [];
        foreach ($typeHits as $articleId) {
            if ($articleId <= 0 || ! $this->articleMatchesSite($articleId, $siteId)) {
                continue;
            }
            $out[] = ['article_id' => $articleId, 'matched_by' => 'automation_origin.seo_project_task'];
        }

        return $out;
    }

    /**
     * Stale task.article_id that is actually a WordPress post id for this site.
     *
     * @return list<array{article_id: int, matched_by: string}>
     */
    private function candidatesFromStaleTaskArticleAsWpPostId(SeoProjectTask $task, ?int $siteId): array
    {
        $maybeWpPostId = (int) ($task->article_id ?? 0);
        if ($maybeWpPostId <= 0 || $siteId === null || $siteId <= 0) {
            return [];
        }

        // Only when the raw id is NOT a local article (already filtered by reconcileTask),
        // treat it as a possible WP post id.
        if (LocalArticleAssociationGuard::isLocalArticleId($maybeWpPostId, null)) {
            return [];
        }

        $matches = SeoArticle::query()
            ->where('site_id', $siteId)
            ->whereWpPostId($maybeWpPostId)
            ->limit(3)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $out = [];
        foreach ($matches as $articleId) {
            $out[] = ['article_id' => $articleId, 'matched_by' => 'exact.wp_post_id'];
        }

        return $out;
    }

    private function resolveSiteId(SeoProjectTask $task, ?int $siteId): ?int
    {
        // Prefer item domain — project.site_id may be null on domain-neutral execution.
        $fromTask = (int) ($task->site_id ?? 0);
        if ($fromTask > 0) {
            return $fromTask;
        }
        if ($siteId !== null && $siteId > 0) {
            return $siteId;
        }
        $task->loadMissing('project');
        $fromProject = (int) ($task->project?->site_id ?? 0);

        return $fromProject > 0 ? $fromProject : null;
    }

    private function tableExists(string $table): bool
    {
        try {
            return Schema::connection('omi_seo_ai')->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<array{article_id: int, matched_by: string}>
     */
    private function candidatesFromContentProjectRunMeta(int $taskId, ?int $siteId): array
    {
        if ($taskId <= 0 || ! $this->tableExists('article_meta')) {
            return [];
        }

        $metas = ArticleMeta::query()
            ->where('meta_key', 'content_project_run')
            ->where(static function ($q) use ($taskId): void {
                $q->where('meta_value', 'like', '%"task_id":'.$taskId.'%')
                    ->orWhere('meta_value', 'like', '%"task_id": '.$taskId.'%')
                    ->orWhere('meta_value', 'like', '%"task_id":"'.$taskId.'"%')
                    ->orWhere('meta_value', 'like', '%"task_id": "'.$taskId.'"%');
            })
            ->limit(50)
            ->get(['article_id', 'meta_value']);

        $out = [];
        foreach ($metas as $meta) {
            $decoded = json_decode((string) ($meta->meta_value ?? ''), true);
            if (! is_array($decoded) || (int) ($decoded['task_id'] ?? 0) !== $taskId) {
                continue;
            }
            $articleId = (int) ($meta->article_id ?? 0);
            if ($articleId <= 0) {
                continue;
            }
            if (! $this->articleMatchesSite($articleId, $siteId)) {
                continue;
            }
            $out[] = ['article_id' => $articleId, 'matched_by' => 'article_meta.content_project_run'];
        }

        return $out;
    }

    /**
     * @return list<array{article_id: int, matched_by: string}>
     */
    private function candidatesFromRunItems(int $taskId, ?int $siteId): array
    {
        if ($taskId <= 0 || ! $this->tableExists('seo_project_run_items')) {
            return [];
        }

        $items = SeoProjectRunItem::query()
            ->where('task_id', $taskId)
            ->orderByDesc('id')
            ->limit(30)
            ->get(['id', 'article_id', 'output_snapshot']);

        $out = [];
        foreach ($items as $item) {
            $columnId = (int) ($item->article_id ?? 0);
            if ($columnId > 0 && $this->articleMatchesSite($columnId, $siteId)) {
                $out[] = ['article_id' => $columnId, 'matched_by' => 'run_item.article_id'];
            }

            $snapshot = is_array($item->output_snapshot ?? null) ? $item->output_snapshot : [];
            $fromSnap = (int) (
                $snapshot['article_id']
                ?? data_get($snapshot, 'result.article_id')
                ?? data_get($snapshot, 'context.article_id')
                ?? 0
            );
            if ($fromSnap > 0 && $this->articleMatchesSite($fromSnap, $siteId)) {
                $out[] = ['article_id' => $fromSnap, 'matched_by' => 'run_item.output_snapshot.article_id'];
            }
        }

        return $out;
    }

    /**
     * @return list<array{article_id: int, matched_by: string}>
     */
    private function candidatesFromPromptLinks(int $taskId, ?int $siteId): array
    {
        if ($taskId <= 0 || ! $this->tableExists('seo_prompt_result_links')) {
            return [];
        }

        $ids = SeoPromptResultLink::query()
            ->where('project_task_id', $taskId)
            ->whereNotNull('article_id')
            ->where('article_id', '>', 0)
            ->orderByDesc('id')
            ->limit(20)
            ->pluck('article_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $out = [];
        foreach ($ids as $articleId) {
            if (! $this->articleMatchesSite($articleId, $siteId)) {
                continue;
            }
            $out[] = ['article_id' => $articleId, 'matched_by' => 'prompt_result_link.article_id'];
        }

        return $out;
    }

    /**
     * @return list<array{article_id: int, matched_by: string}>
     */
    private function candidatesFromTaskEvents(int $taskId, ?int $siteId): array
    {
        if ($taskId <= 0 || ! $this->tableExists('seo_project_task_events')) {
            return [];
        }

        $events = SeoProjectTaskEvent::query()
            ->where('task_id', $taskId)
            ->whereIn('event', [
                SeoProjectTaskEventType::ArticleLinked->value,
                SeoProjectTaskEventType::ArticleCreated->value,
            ])
            ->orderByDesc('id')
            ->limit(30)
            ->get(['payload']);

        $out = [];
        foreach ($events as $event) {
            $payload = is_array($event->payload ?? null) ? $event->payload : [];
            $articleId = (int) ($payload['article_id'] ?? 0);
            if ($articleId <= 0) {
                continue;
            }
            if (! $this->articleMatchesSite($articleId, $siteId)) {
                continue;
            }
            $out[] = ['article_id' => $articleId, 'matched_by' => 'task_event.article_id'];
        }

        return $out;
    }

    /**
     * Exact slug or absolute permalink only — never fuzzy title LIKE.
     *
     * @return list<array{article_id: int, matched_by: string}>
     */
    private function candidatesFromExactSlugOrPermalink(SeoProjectTask $task, ?int $siteId): array
    {
        $source = trim((string) ($task->source_content ?? ''));
        $title = trim((string) ($task->title ?? ''));
        $hints = array_values(array_unique(array_filter([$source, $title], static fn (string $v): bool => $v !== '')));
        if ($hints === [] || $siteId === null || $siteId <= 0) {
            return [];
        }

        $out = [];
        foreach ($hints as $hint) {
            $slug = $this->extractExactSlug($hint);
            if ($slug !== null) {
                $matches = SeoArticle::query()
                    ->where('site_id', $siteId)
                    ->where('slug', $slug)
                    ->limit(3)
                    ->pluck('id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();
                foreach ($matches as $articleId) {
                    $out[] = ['article_id' => $articleId, 'matched_by' => 'exact.slug'];
                }
            }

            if ($this->looksLikeAbsoluteUrl($hint) && $this->tableExists('article_meta')) {
                $matches = ArticleMeta::query()
                    ->where('meta_key', 'wp_permalink')
                    ->where('meta_value', $hint)
                    ->limit(3)
                    ->pluck('article_id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();
                foreach ($matches as $articleId) {
                    if ($this->articleMatchesSite($articleId, $siteId)) {
                        $out[] = ['article_id' => $articleId, 'matched_by' => 'exact.wp_permalink'];
                    }
                }
            }

            if (ctype_digit($hint)) {
                $wpPostId = (int) $hint;
                if ($wpPostId > 0) {
                    $matches = SeoArticle::query()
                        ->where('site_id', $siteId)
                        ->whereWpPostId($wpPostId)
                        ->limit(3)
                        ->pluck('id')
                        ->map(static fn (mixed $id): int => (int) $id)
                        ->all();
                    foreach ($matches as $articleId) {
                        $out[] = ['article_id' => $articleId, 'matched_by' => 'exact.wp_post_id'];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Rewrite picker stores Existing Article title in source_content.
     * Exact title (=) + same site only — never LIKE / partial match.
     *
     * @return list<array{article_id: int, matched_by: string}>
     */
    private function candidatesFromExactTitle(SeoProjectTask $task, ?int $siteId): array
    {
        $type = SeoProjectTask::normalizeType($task->type);
        if ($type === SeoProjectTask::TYPE_CREATE) {
            // CREATE titles were often AI-generated. After the legacy keyword-binding
            // bug they are unsafe as automatic identity.
            return [];
        }

        if ($siteId === null || $siteId <= 0) {
            return [];
        }

        $hints = [];
        foreach ([
            trim((string) ($task->source_content ?? '')),
            trim((string) ($task->title ?? '')),
        ] as $hint) {
            if ($hint === '' || $this->looksLikeAbsoluteUrl($hint) || ctype_digit($hint)) {
                continue;
            }
            // Slug-like tokens handled by exact.slug path — still allow exact title if stored that way.
            $hints[] = $hint;
        }
        $hints = array_values(array_unique($hints));
        if ($hints === []) {
            return [];
        }

        $out = [];
        foreach ($hints as $hint) {
            $rows = SeoArticle::query()
                ->where('site_id', $siteId)
                ->where('title', $hint)
                ->limit(3)
                ->get(['id', 'title']);

            $mapped = [];
            foreach ($rows as $row) {
                $mapped[] = ['id' => (int) $row->id, 'title' => (string) ($row->title ?? '')];
            }
            foreach ($this->matchExactTitleCandidates($hint, $mapped) as $candidate) {
                $out[] = $candidate;
            }
        }

        return $out;
    }

    private function extractExactSlug(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || str_contains($value, ' ')) {
            return null;
        }

        if ($this->looksLikeAbsoluteUrl($value)) {
            $path = parse_url($value, PHP_URL_PATH);
            if (! is_string($path) || $path === '' || $path === '/') {
                return null;
            }
            $value = basename(rtrim($path, '/'));
        }

        $value = trim($value, '/');
        if ($value === '' || str_contains($value, '/') || str_contains($value, ' ')) {
            return null;
        }

        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/i', $value)) {
            return null;
        }

        return mb_strtolower($value);
    }

    private function looksLikeAbsoluteUrl(string $value): bool
    {
        return (bool) preg_match('#^https?://#i', $value);
    }

    private function articleMatchesSite(int $articleId, ?int $siteId): bool
    {
        if ($articleId <= 0) {
            return false;
        }
        if ($siteId === null || $siteId <= 0) {
            return SeoArticle::query()->whereKey($articleId)->exists();
        }

        return SeoArticle::query()
            ->whereKey($articleId)
            ->where('site_id', $siteId)
            ->exists();
    }

    /**
     * Public ownership gate for manual Existing Article attach.
     * Non-archived task holding article_id = conflict (do not steal silently).
     */
    public function ownershipConflictMessage(int $taskId, int $articleId): ?string
    {
        return $this->ownershipConflict($taskId, $articleId);
    }

    public function articleBelongsToSite(int $articleId, int $siteId): bool
    {
        return $this->articleMatchesSite($articleId, $siteId);
    }

    private function ownershipConflict(int $taskId, int $articleId): ?string
    {
        $other = SeoProjectTask::query()
            ->where('article_id', $articleId)
            ->whereKeyNot($taskId)
            ->whereNull('archived_at')
            ->first(['id']);

        if ($other instanceof SeoProjectTask) {
            return 'Article #'.$articleId.' already linked to active task #'.(int) $other->id.'.';
        }

        return null;
    }

    private function persistAssociation(SeoProjectTask $task, int $articleId): bool
    {
        $taskId = (int) $task->getKey();
        $previousId = (int) ($task->article_id ?? 0);

        try {
            DB::connection('omi_seo_ai')->transaction(function () use ($taskId, $articleId, $previousId): void {
                $conflict = SeoProjectTask::query()
                    ->where('article_id', $articleId)
                    ->whereKeyNot($taskId)
                    ->whereNull('archived_at')
                    ->lockForUpdate()
                    ->exists();
                if ($conflict) {
                    throw new \RuntimeException('article_owned_elsewhere');
                }

                SeoProjectTask::query()
                    ->where('article_id', $articleId)
                    ->whereKeyNot($taskId)
                    ->whereNotNull('archived_at')
                    ->update(['article_id' => null]);

                $payload = ['article_id' => $articleId];
                $locked = SeoProjectTask::query()->whereKey($taskId)->lockForUpdate()->first();
                if (! $locked instanceof SeoProjectTask) {
                    throw new \RuntimeException('task_missing');
                }
                if ($locked->connected_at === null) {
                    $payload['connected_at'] = now();
                }
                SeoProjectTask::query()->whereKey($taskId)->update($payload);

                // Heal current/latest run-item mirror only — do not rewrite historical rows indiscriminately.
                $latestItem = SeoProjectRunItem::query()
                    ->where('task_id', $taskId)
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();
                if ($latestItem instanceof SeoProjectRunItem) {
                    $itemArticleId = (int) ($latestItem->article_id ?? 0);
                    $itemNeedsHeal = $itemArticleId <= 0
                        || $itemArticleId === $previousId
                        || LocalArticleAssociationGuard::resolveLocalArticleId($itemArticleId) === null;
                    if ($itemNeedsHeal && $itemArticleId !== $articleId) {
                        $latestItem->article_id = $articleId;
                        $latestItem->save();
                    }
                }
            });
        } catch (\Throwable $e) {
            RuntimeLogger::warning('content_project.existing_article_repair_failed', [
                'task_id' => $taskId,
                'article_id' => $articleId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $task->refresh();

        return (int) ($task->article_id ?? 0) === $articleId;
    }
}
