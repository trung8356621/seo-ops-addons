<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;


use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskLifecycleService;
use Omnichannel\Addons\Content\Enums\ArticleReviewActionType;
use Omnichannel\Addons\Content\Enums\ArticleReviewStatus;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoArticleReview;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Content\Exceptions\ArticleReviewException;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\User;
use App\Support\RuntimeLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Single source of truth cho workflow review bài viết:
 * submit_review (CM) → approve (planner+) → archive/Hoàn tất duyệt (manager).
 *
 * Canonical column: `articles.review_status` (+ `reviewed_at` approval timestamp).
 *
 * Action `archive` chỉ cập nhật trạng thái nghiệp vụ (review_status = archived).
 * Không detach task, không set content_archived_at, không tạo archive lẻ.
 * Đơn vị lưu trữ kho = Content Project ({@see ArchiveContentProjectService}).
 */
final class ArticleReviewService
{
    /**
     * @var array<string, array{from: ArticleReviewStatus, to: ArticleReviewStatus}>
     */
    private const TRANSITIONS = [
        'submit_review' => ['from' => ArticleReviewStatus::Draft, 'to' => ArticleReviewStatus::PendingReview],
        'approve' => ['from' => ArticleReviewStatus::PendingReview, 'to' => ArticleReviewStatus::Approved],
        'archive' => ['from' => ArticleReviewStatus::Approved, 'to' => ArticleReviewStatus::Archived],
        'reopen' => ['from' => ArticleReviewStatus::Archived, 'to' => ArticleReviewStatus::Approved],
        'unapprove' => ['from' => ArticleReviewStatus::Approved, 'to' => ArticleReviewStatus::PendingReview],
    ];

    /**
     * Metadata phụ của lần `performAction()` gần nhất (vd. project_id liên kết).
     * Reset mỗi lần gọi `performAction()`, đọc lại qua
     * {@see self::lastSideEffectMeta()} hoặc {@see self::toApiPayload()}.
     *
     * @var array<string, mixed>
     */
    private array $lastSideEffectMeta = [];

    public function __construct(
        private readonly SeoProjectTaskLifecycleService $taskLifecycle,
    ) {}

    /**
     * Canonical "approved for lifecycle/publish" — ONLY review_status=approved.
     * Archived is NOT approved.
     */
    public function isCanonicallyApproved(SeoArticle $article): bool
    {
        return $this->resolveStatus($article) === ArticleReviewStatus::Approved;
    }

    /**
     * Content Project / bulk path: force review_status=approved from draft|pending_review.
     * Idempotent when already approved. Archived (hoàn tất duyệt) is NOT approved — conflict.
     * Does NOT emit BusinessHook / ContentProjectItemsApproved.
     *
     * @return array{
     *     already_approved: bool,
     *     review: ?SeoArticleReview,
     *     status: ArticleReviewStatus,
     *     deleted_media_count: int
     * }
     */
    public function ensureApproved(
        SeoArticle $article,
        User $user,
        ?string $note = null,
        string $source = 'content_project',
    ): array {
        $this->authorize(ArticleReviewActionType::Approve, $user);
        $normalizedNote = $this->normalizeNote($note);
        $this->lastSideEffectMeta = [];

        return DB::connection($article->getConnectionName())->transaction(
            function () use ($article, $user, $normalizedNote, $source): array {
                /** @var SeoArticle|null $locked */
                $locked = SeoArticle::query()
                    ->whereKey($article->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $locked instanceof SeoArticle) {
                    throw ArticleReviewException::invalidTransition(
                        __('seo-content-ai::filament.article_review.errors.invalid_transition'),
                    );
                }

                $current = $this->resolveStatus($locked);
                if ($current === ArticleReviewStatus::Archived) {
                    throw ArticleReviewException::conflict(
                        'Article is archived (hoàn tất duyệt); reopen before approve.',
                    );
                }

                if ($current === ArticleReviewStatus::Approved) {
                    $deleted = $this->applyApprovalSideEffects($locked, true);

                    $article->setRawAttributes($locked->getAttributes());
                    $article->syncOriginal();

                    return [
                        'already_approved' => true,
                        'review' => null,
                        'status' => $current,
                        'deleted_media_count' => $deleted,
                    ];
                }

                $from = $current;
                $to = ArticleReviewStatus::Approved;

                $deleted = $this->applyApprovalSideEffects($locked, true);
                $locked->forceFill([
                    'review_status' => $to->value,
                    'reviewed_at' => $locked->reviewed_at ?? now(),
                ])->save();

                $review = SeoArticleReview::query()->create([
                    'article_id' => (int) $locked->getKey(),
                    'action_type' => ArticleReviewActionType::Approve->value,
                    'from_status' => $from->value,
                    'to_status' => $to->value,
                    'reviewer_id' => (int) $user->id,
                    'reviewer_role' => SeoAccessControl::effectiveRole(),
                    'note' => $normalizedNote !== null
                        ? $normalizedNote
                        : 'ensureApproved:'.$source,
                ]);

                $article->setRawAttributes($locked->getAttributes());
                $article->syncOriginal();

                RuntimeLogger::info('seo.article_review.ensure_approved', [
                    'article_id' => (int) $locked->getKey(),
                    'from' => $from->value,
                    'to' => $to->value,
                    'source' => $source,
                    'already_approved' => false,
                ]);

                return [
                    'already_approved' => false,
                    'review' => $review,
                    'status' => $to,
                    'deleted_media_count' => $deleted,
                ];
            },
        );
    }

    public function performAction(
        SeoArticle $article,
        User $user,
        ArticleReviewActionType $action,
        ?string $note = null,
    ): SeoArticleReview {
        $this->authorize($action, $user);

        $normalizedNote = $this->normalizeNote($note);
        $this->lastSideEffectMeta = [];

        try {
            return DB::connection($article->getConnectionName())->transaction(
                function () use ($article, $user, $action, $normalizedNote): SeoArticleReview {
                    /** @var SeoArticle|null $locked */
                    $locked = SeoArticle::query()
                        ->whereKey($article->getKey())
                        ->lockForUpdate()
                        ->first();

                    if (! $locked instanceof SeoArticle) {
                        throw ArticleReviewException::invalidTransition(
                            __('seo-content-ai::filament.article_review.errors.invalid_transition'),
                        );
                    }

                    $currentStatus = $this->resolveStatus($locked);
                    $transition = $this->validateTransition($action, $currentStatus);

                    if (
                        $action === ArticleReviewActionType::SubmitReview
                        && ! ArticleResource::articleIsInContentProject($locked)
                    ) {
                        throw ArticleReviewException::invalidTransition(
                            __('seo-content-ai::filament.article_review.errors.invalid_transition'),
                        );
                    }

                    $this->applySideEffects($locked, $user, $action);

                    $locked->forceFill(['review_status' => $transition['to']->value])->save();

                    $review = SeoArticleReview::query()->create([
                        'article_id' => (int) $locked->getKey(),
                        'action_type' => $action->value,
                        'from_status' => $transition['from']?->value,
                        'to_status' => $transition['to']->value,
                        'reviewer_id' => (int) $user->id,
                        'reviewer_role' => SeoAccessControl::effectiveRole(),
                        'note' => $normalizedNote,
                    ]);

                    $article->setRawAttributes($locked->getAttributes());
                    $article->syncOriginal();

                    return $review;
                },
            );
        } catch (ArticleReviewException $exception) {
            RuntimeLogger::warning('seo.article_review.action_rejected', [
                'article_id' => (int) $article->getKey(),
                'action' => $action->value,
                'code' => $exception->errorCode(),
            ]);

            throw $exception;
        } catch (Throwable $exception) {
            RuntimeLogger::report($exception, [
                'article_id' => (int) $article->getKey(),
                'action' => $action->value,
            ]);

            throw $exception;
        }
    }

    /**
     * @return list<array{type: string, label: string, quick_label: string, note_label: string, note_modal_title: string}>
     */
    public function availableActions(SeoArticle $article, User $user): array
    {
        $status = $this->resolveStatus($article);
        $actions = [];

        if (
            $status === ArticleReviewStatus::Draft
            && $this->actorMay($user, ArticleReviewActionType::SubmitReview)
            && ArticleResource::articleIsInContentProject($article)
        ) {
            $actions[] = $this->describeAction(ArticleReviewActionType::SubmitReview);
        }

        if ($status === ArticleReviewStatus::PendingReview && $this->actorMay($user, ArticleReviewActionType::Approve)) {
            $actions[] = $this->describeAction(ArticleReviewActionType::Approve);
        }

        if ($status === ArticleReviewStatus::Approved) {
            if ($this->actorMay($user, ArticleReviewActionType::Archive)) {
                $actions[] = $this->describeAction(ArticleReviewActionType::Archive);
            } elseif ($this->actorMay($user, ArticleReviewActionType::Unapprove)) {
                $actions[] = $this->describeAction(ArticleReviewActionType::Unapprove);
            }
        }

        if ($status === ArticleReviewStatus::Archived && $this->actorMay($user, ArticleReviewActionType::Reopen)) {
            $actions[] = $this->describeAction(ArticleReviewActionType::Reopen);
        }

        return $actions;
    }

    public function resolveStatus(SeoArticle $article): ArticleReviewStatus
    {
        $stored = ArticleReviewStatus::tryFromString($article->review_status ?? null);
        if ($stored instanceof ArticleReviewStatus) {
            return $stored;
        }

        // content_archived_at = Content Project archive flag — NOT review_status archived.
        return ArticleReviewStatus::Draft;
    }

    /**
     * @return Collection<int, SeoArticleReview>
     */
    public function history(SeoArticle $article): Collection
    {
        return SeoArticleReview::query()
            ->where('article_id', (int) $article->getKey())
            ->with('reviewer:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return array{success: bool, message: string, data: array{article_id: int, review_status: string, available_actions: array<int, array<string, string>>, latest_review: array<string, mixed>|null, content_project?: array<string, mixed>}}
     */
    public function toApiPayload(SeoArticle $article, User $user, ?SeoArticleReview $latest = null): array
    {
        $status = $this->resolveStatus($article);
        $latest ??= SeoArticleReview::query()
            ->where('article_id', (int) $article->getKey())
            ->with('reviewer:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $message = $latest instanceof SeoArticleReview
            ? (string) __('seo-content-ai::filament.article_review.success.'.$latest->action_type)
            : '';

        $data = [
            'article_id' => (int) $article->getKey(),
            'review_status' => $status->value,
            'available_actions' => $this->availableActions($article, $user),
            'latest_review' => $latest instanceof SeoArticleReview ? $this->reviewToArray($latest) : null,
        ];

        if (isset($this->lastSideEffectMeta['content_project'])) {
            $data['content_project'] = $this->lastSideEffectMeta['content_project'];
        }

        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];
    }

    private function authorize(ArticleReviewActionType $action, User $user): void
    {
        if (! $this->actorMay($user, $action)) {
            throw ArticleReviewException::forbidden(
                __('seo-content-ai::filament.article_review.errors.forbidden'),
            );
        }
    }

    /**
     * Session auth + role simulation khi actor = user hiện tại; automation dùng seo_role của actor.
     */
    private function actorMay(User $user, ArticleReviewActionType $action): bool
    {
        if ((int) auth()->id() === (int) $user->id) {
            return match ($action) {
                ArticleReviewActionType::SubmitReview => SeoAccessControl::canSubmitArticleReview(),
                ArticleReviewActionType::Approve => SeoAccessControl::canApproveArticleReview(),
                ArticleReviewActionType::Archive => SeoAccessControl::canFinalizeArticleReview(),
                ArticleReviewActionType::Reopen => SeoAccessControl::canFinalizeArticleReview()
                    || SeoAccessControl::canApproveArticleReview(),
                ArticleReviewActionType::Unapprove => SeoAccessControl::canApproveArticleReview(),
                default => false,
            };
        }

        $role = SeoAccessControl::normalizeRole((string) ($user->seo_role ?? SeoAccessControl::ROLE_CONTENT_MANAGER));

        return match ($action) {
            ArticleReviewActionType::SubmitReview => $role === SeoAccessControl::ROLE_CONTENT_MANAGER,
            ArticleReviewActionType::Approve,
            ArticleReviewActionType::Reopen,
            ArticleReviewActionType::Unapprove => in_array($role, [
                SeoAccessControl::ROLE_PLANNER,
                SeoAccessControl::ROLE_MANAGER,
            ], true),
            ArticleReviewActionType::Archive => $role === SeoAccessControl::ROLE_MANAGER,
            default => false,
        };
    }

    /**
     * @return array{from: ArticleReviewStatus, to: ArticleReviewStatus}
     */
    private function validateTransition(ArticleReviewActionType $action, ArticleReviewStatus $currentStatus): array
    {
        $transition = self::TRANSITIONS[$action->value] ?? null;
        if ($transition === null) {
            throw ArticleReviewException::invalidTransition(
                __('seo-content-ai::filament.article_review.errors.invalid_transition'),
            );
        }

        if ($currentStatus !== $transition['from']) {
            throw ArticleReviewException::conflict(
                __('seo-content-ai::filament.article_review.errors.stale_status'),
            );
        }

        return $transition;
    }

    private function applySideEffects(SeoArticle $article, User $user, ArticleReviewActionType $action): void
    {
        match ($action) {
            ArticleReviewActionType::Approve => $this->applyApprovalSideEffects($article, true),
            ArticleReviewActionType::Archive => $this->completeReviewWithoutDetaching($article),
            ArticleReviewActionType::Reopen => $this->reopenReviewKeepingProjectLinks($article, $user),
            ArticleReviewActionType::Unapprove => $this->applyApprovalSideEffects($article, false),
            default => null,
        };
    }

    /**
     * Approve-side media cleanup + reviewed_at; unapprove clears reviewed_at.
     *
     * @return int deleted local media count when approving
     */
    private function applyApprovalSideEffects(SeoArticle $article, bool $approved): int
    {
        if (! $approved) {
            $article->forceFill(['reviewed_at' => null])->save();

            return 0;
        }

        $deleted = ArticleResource::deleteLocalMediaForArticle($article);
        app(ArticleWpSyncQueueService::class)->clearQueueEntry($article->fresh() ?? $article);

        if ($article->reviewed_at === null) {
            $article->forceFill(['reviewed_at' => now()])->save();
        }

        return $deleted;
    }

    /**
     * Hoàn tất duyệt — không tạo archive lẻ, không set content_archived_at, không detach task.
     * Bài vẫn thuộc Content Project; kho lưu trữ chỉ qua ArchiveContentProjectService.
     */
    private function completeReviewWithoutDetaching(SeoArticle $article): void
    {
        $projectId = $this->resolveLinkedProjectId((int) $article->getKey());

        $this->lastSideEffectMeta['content_project'] = [
            'assignment' => $projectId !== null ? 'active' : 'unassigned',
            'project_id' => $projectId,
            'detached_task_ids' => [],
        ];
    }

    /**
     * Reopen: clear legacy content_archived_* nếu còn; restore task đã detach bởi flow archive lẻ cũ.
     * Flow mới (sau khi bỏ detach) không có task archived → restore no-op.
     */
    private function reopenReviewKeepingProjectLinks(SeoArticle $article, User $user): void
    {
        // Legacy content_archived_* columns dropped from articles; seo_content_archive_items
        // is sole owner now. Nothing to clear here — archive item lifecycle is managed by
        // SeoProjectArchiveService, not the review flow.

        $tasks = SeoProjectTask::query()
            ->where('article_id', (int) $article->getKey())
            ->archived()
            ->whereHas('project')
            ->lockForUpdate()
            ->orderBy('id')
            ->get();

        $restoredTaskIds = [];
        $projectId = null;

        foreach ($tasks as $task) {
            $this->taskLifecycle->restore($task, (int) $user->id, ['from_article_review' => true]);
            $restoredTaskIds[] = (int) $task->id;
            $projectId ??= $task->project_id !== null ? (int) $task->project_id : null;
        }

        $projectId ??= $this->resolveLinkedProjectId((int) $article->getKey());

        $this->lastSideEffectMeta['content_project'] = [
            'assignment' => ($restoredTaskIds !== [] || $projectId !== null) ? 'active' : 'unassigned',
            'project_id' => $projectId,
            'restored_task_ids' => $restoredTaskIds,
        ];
    }

    private function resolveLinkedProjectId(int $articleId): ?int
    {
        if ($articleId <= 0) {
            return null;
        }

        $projectId = SeoProjectTask::query()
            ->where('article_id', $articleId)
            ->whereNull('archived_at')
            ->orderByDesc('id')
            ->value('project_id');

        return $projectId !== null ? (int) $projectId : null;
    }

    /**
     * Metadata phụ (Content Project detach/restore) của lần `performAction()` gần nhất trên
     * cùng instance service. Rỗng nếu action không phải archive/reopen hoặc chưa gọi.
     *
     * @return array<string, mixed>
     */
    public function lastSideEffectMeta(): array
    {
        return $this->lastSideEffectMeta;
    }

    private function normalizeNote(?string $note): ?string
    {
        $trimmed = trim((string) $note);
        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) < 3) {
            throw ArticleReviewException::invalidTransition(
                __('seo-content-ai::filament.article_review.errors.note_too_short'),
            );
        }

        return mb_substr($trimmed, 0, 5000);
    }

    /**
     * @return array<string, string>
     */
    private function describeAction(ArticleReviewActionType $action): array
    {
        $key = $action->value;

        return [
            'type' => $key,
            'label' => (string) __('seo-content-ai::filament.article_review.actions.'.$key.'.label'),
            'quick_label' => (string) __('seo-content-ai::filament.article_review.actions.'.$key.'.quick_label'),
            'note_label' => (string) __('seo-content-ai::filament.article_review.actions.'.$key.'.note_label'),
            'note_modal_title' => (string) __('seo-content-ai::filament.article_review.actions.'.$key.'.note_modal_title'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewToArray(SeoArticleReview $review): array
    {
        /** @var User|null $reviewer */
        $reviewer = $review->relationLoaded('reviewer') ? $review->reviewer : null;

        return [
            'id' => (int) $review->id,
            'action_type' => (string) $review->action_type,
            'from_status' => $review->from_status,
            'to_status' => (string) $review->to_status,
            'reviewer_id' => (int) $review->reviewer_id,
            'reviewer_role' => $review->reviewer_role,
            'reviewer_name' => $reviewer?->name,
            'note' => $review->note,
            'created_at' => $review->created_at?->toIso8601String(),
        ];
    }
}
