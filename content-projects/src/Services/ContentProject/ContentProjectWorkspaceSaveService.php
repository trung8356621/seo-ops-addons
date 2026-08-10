<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Agent\Automation\Contracts\BusinessActionDispatcher;
use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Agent\Automation\Support\ArticleContentConflictGuard;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleEditorBundleApplyService;
use Omnichannel\Addons\Content\Services\ArticleLastSavedTimestampService;
use Omnichannel\Addons\Media\Services\ArticlePostImagesService;
use Omnichannel\Addons\WordPress\Services\ArticleWordPressSyncFlagService;
use Omnichannel\Addons\Content\Support\ArticleEditorSaveContext;
use App\Models\User;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sync/Save khi bài thuộc Content Project đang hoạt động:
 * chỉ Save Workspace (Laravel) — không gọi WordPress API / không enqueue legacy sync queue.
 */
final class ContentProjectWorkspaceSaveService
{
    public const SAVE_MODE = 'project_local_save';

    public function __construct(
        private readonly ArticleEditorBundleApplyService $bundleApply,
        private readonly BusinessActionDispatcher $actions,
        private readonly ArticlePostImagesService $postImages,
        private readonly ContentProjectArticleMembership $membership,
        private readonly ArticleContentConflictGuard $conflictGuard,
        private readonly ArticleWordPressSyncFlagService $syncFlags,
        private readonly ArticleLastSavedTimestampService $lastSavedTimestamps,
    ) {}

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    public function saveFromEditorBundle(SeoArticle $article, array $bundle, User $actor, string $initiatedFrom): array
    {
        if (! $this->membership->belongsToActiveContentProject($article)) {
            return [
                'success' => false,
                'status' => 'blocked',
                'queued' => false,
                'workspace_only' => true,
                'save_mode' => self::SAVE_MODE,
                'message' => 'Article không thuộc Content Project đang hoạt động.',
            ];
        }

        $context = ArticleEditorSaveContext::fromBundle($article, $bundle);
        $html = (string) ($bundle['html'] ?? '');
        $submittedHash = $this->conflictGuard->contentHash($html);

        try {
            // Không bọc content.update trong TX dài — tránh Lock wait trên articles.body
            // khi side-effect (images/revision/links) chạy trong cùng transaction.
            $this->bundleApply->apply($article, $bundle, $context);

            $fresh = $article->fresh() ?? $article;
            $persist = $this->actions->dispatch(
                'article.content.update',
                [
                    'article_id' => (int) $fresh->id,
                    'content' => $html,
                    'title' => $context->title,
                    'slug' => $context->slug,
                    'status' => $context->status,
                    'post_type' => $context->postType,
                    'visibility' => $context->visibility,
                    'publish_day' => $context->publishDay,
                    'publish_month' => $context->publishMonth,
                    'publish_year' => $context->publishYear,
                    'publish_hour' => $context->publishHour,
                    'publish_minute' => $context->publishMinute,
                    'seo_meta_description' => $context->seoMetaDescription,
                    'focus_keyword' => $context->focusKeyword,
                ],
                ActionContext::fromArray([
                    'origin' => 'content_project_workspace_save',
                    'correlation_id' => Str::uuid()->toString(),
                    'actor_id' => (int) $actor->id,
                    'site_id' => (int) ($fresh->site_id ?? 0) ?: null,
                ]),
            );

            if (! $persist->success) {
                throw new ContentProjectWorkspaceSaveException(
                    (string) ($persist->error['message'] ?? 'Không lưu được workspace.'),
                    (string) ($persist->error['code'] ?? 'persist_failed'),
                );
            }

            $reloaded = SeoArticle::query()->find((int) $fresh->id);
            if (! $reloaded instanceof SeoArticle) {
                throw new ContentProjectWorkspaceSaveException(
                    'Article disappeared after save.',
                    'article_missing_after_save',
                );
            }

            $persistedHash = $this->conflictGuard->contentHash((string) ($reloaded->body ?? ''));
            if ($submittedHash !== '' && ! hash_equals($submittedHash, $persistedHash)) {
                throw new ContentProjectWorkspaceSaveException(
                    'Persisted content hash mismatch — save rejected.',
                    'persist_hash_mismatch',
                );
            }

            try {
                $this->postImages->syncFromHtml($reloaded, (string) ($reloaded->body ?? $html));
            } catch (\Throwable $e) {
                RuntimeLogger::warning('content_project_workspace_media_meta_sync_failed', [
                    'article_id' => (int) $reloaded->id,
                    'message' => $e->getMessage(),
                ]);
            }

            $savedAt = now();
            DB::connection('omi_seo_ai')->transaction(function () use ($reloaded, $persistedHash, $savedAt): void {
                $this->lastSavedTimestamps->touchManualSaved($reloaded);
                $this->syncFlags->markLocalEditPending($reloaded);
                $this->syncFlags->rememberLocalContentHash($reloaded, $persistedHash);
                $reloaded->forceFill([
                    'last_synced_at' => $savedAt,
                ])->saveQuietly();
            });

            $task = $this->membership->activeTaskForArticle($reloaded);

            RuntimeLogger::info('content_project_workspace_saved', [
                'article_id' => (int) $reloaded->id,
                'project_id' => $task?->project_id,
                'task_id' => $task?->id,
                'actor_id' => (int) $actor->id,
                'initiated_from' => $initiatedFrom,
                'wp_api_called' => false,
                'schedule_touched' => false,
                'queued' => false,
                'content_hash' => $persistedHash,
            ]);

            $canonical = [
                'article' => $reloaded,
                'task_id' => $task !== null ? (int) $task->id : null,
                'project_id' => $task !== null ? (int) ($task->project_id ?? 0) ?: null : null,
                'content_hash' => $persistedHash,
                'saved_at' => $savedAt->toIso8601String(),
                'has_unpublished_changes' => true,
            ];
        } catch (ContentProjectWorkspaceSaveException $e) {
            return [
                'success' => false,
                'status' => 'blocked',
                'queued' => false,
                'close_editor' => false,
                'workspace_only' => true,
                'save_mode' => self::SAVE_MODE,
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
                'notification' => [
                    'title' => __('seo-content-ai::filament.automation.content_project_workspace_save_failed_title'),
                    'body' => $e->getMessage(),
                    'status' => 'danger',
                ],
            ];
        } catch (\Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'content_project.workspace_save',
                'article_id' => (int) $article->id,
            ]);

            return [
                'success' => false,
                'status' => 'blocked',
                'queued' => false,
                'close_editor' => false,
                'workspace_only' => true,
                'save_mode' => self::SAVE_MODE,
                'message' => $e->getMessage(),
                'notification' => [
                    'title' => __('seo-content-ai::filament.automation.content_project_workspace_save_failed_title'),
                    'body' => $e->getMessage(),
                    'status' => 'danger',
                ],
            ];
        }

        /** @var SeoArticle $savedArticle */
        $savedArticle = $canonical['article'];
        $projectUrl = null;
        if ($canonical['project_id'] !== null) {
            $projectUrl = \Omnichannel\Addons\Content\Filament\Resources\ArticleResource::articleContentProjectUrl($savedArticle);
        }

        return [
            'success' => true,
            'status' => 'workspace_saved',
            'queued' => false,
            'already_queued' => false,
            'close_editor' => true,
            'reload' => false,
            'workspace_only' => true,
            'save_mode' => self::SAVE_MODE,
            'manual' => true,
            'message' => __('seo-content-ai::filament.automation.content_project_workspace_saved'),
            'data' => [
                'article_id' => (int) $savedArticle->id,
                'saved_at' => $canonical['saved_at'],
                'content_hash' => $canonical['content_hash'],
                'project_task_id' => $canonical['task_id'],
                'project_id' => $canonical['project_id'],
                'project_url' => $projectUrl,
                'save_mode' => self::SAVE_MODE,
                'has_unpublished_changes' => true,
                'last_synced_at' => $savedArticle->wordpressLink?->last_synced_at?->toIso8601String(),
                'status' => 'workspace_saved',
            ],
            'notification' => [
                'title' => __('seo-content-ai::filament.automation.content_project_workspace_saved_title'),
                'body' => __('seo-content-ai::filament.automation.content_project_workspace_saved'),
                'status' => 'success',
            ],
        ];
    }
}
