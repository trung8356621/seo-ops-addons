<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentException;
use Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentWriter;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArticleMembership;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueService;
use Omnichannel\Addons\Content\Support\ArticleEditorContentLifecycle;
use Omnichannel\Addons\Content\Support\ArticleEditorSaveContext;
use Omnichannel\Addons\Content\Support\ArticleEditorSessionErrorCode;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use App\Support\LocalArticleSaveTimer;
use Omnichannel\Addons\Media\Services\ArticlePostImagesService;
use Omnichannel\Addons\SearchFoundation\Services\ArticleKeywordLinkReconcileService;
use Omnichannel\Addons\WordPress\Services\ArticleWordPressSyncFlagService;
use Carbon\Carbon;

final class ArticleEditorPersistService
{
    public function __construct(
        private readonly ArticleEditorHtmlSanitizeService $htmlSanitize,
        private readonly ArticleFaqBodySyncService $faqBodySync,
        private readonly ArticlePostImagesService $postImages,
        private readonly ArticleWordPressSyncFlagService $syncFlags,
        private readonly ArticleKeywordLinkReconcileService $keywordLinks,
        private readonly SeoArticleRevisionService $revisions,
        private readonly ContentProjectArticleMembership $contentProjectMembership,
        private readonly ContentProjectPublishingQueueService $publishingQueue,
        private readonly ArticleEditorDocumentWriter $documentWriter,
        private readonly ArticleEditorContentLifecycle $contentLifecycle,
    ) {}

    /**
     * Persist only. Event emission (article.content_updated) là trách nhiệm của caller/Action
     * (UpdateArticleContentAction) — tránh emit trùng lặp khi service này được gọi qua Action.
     *
     * @return array{success: bool, message: string, html?: string, faq_extracted?: bool, faq_count?: int}
     */
    public function persistLocal(
        SeoArticle $article,
        ArticleEditorSaveContext $context,
        string $html,
        bool $deferSeoAnalysis = true,
        ?array $seoAnalysis = null,
    ): array {
        $rejected = $this->rejectUnhydratedEmptyPersist($article, $html);
        if ($rejected !== null) {
            return $rejected;
        }

        $html = $this->persistLocalSilent($article, $context, $html);

        return $this->buildPersistResult($article, $html);
    }

    /**
     * @return array{success: bool, message: string, html?: string, code?: string}
     */
    public function buildPersistResult(SeoArticle $article, string $html): array
    {
        $rejected = $this->rejectUnhydratedEmptyPersist($article, $html);
        if ($rejected !== null) {
            return $rejected;
        }

        if (strlen(trim($html)) < 50 && $this->articleHadSubstantialContent($article)) {
            return [
                'success' => false,
                'code' => 'empty_editor_body',
                'message' => 'Editor trả về nội dung rỗng. Hãy thử lại hoặc dùng Lấy từ WordPress / Restore trước khi lưu.',
            ];
        }

        return [
            'success' => true,
            'message' => 'Content is saved only in SEO system. Use "Sync" to push to WordPress.',
            'html' => $html,
        ];
    }

    public function persistLocalSilent(
        SeoArticle $article,
        ArticleEditorSaveContext $context,
        string $html,
    ): string {
        if ($this->contentLifecycle->shouldRejectEmptyPersist($article, $html)) {
            return (string) ($article->body ?? '');
        }

        $html = $this->writeArticleRow($article, $context, $html);
        $this->runAfterPersistSideEffects($article, $context, $html);

        return $html;
    }

    /**
     * @return array{success: false, code: string, message: string}|null
     */
    public function rejectUnhydratedEmptyPersist(SeoArticle $article, string $html): ?array
    {
        if (! $this->contentLifecycle->shouldRejectEmptyPersist($article, $html)) {
            return null;
        }

        return [
            'success' => false,
            'code' => ArticleEditorSessionErrorCode::LOCAL_CONTENT_SYNC_REQUIRED,
            'message' => 'Nội dung bài viết chưa được đồng bộ từ WordPress. Đồng bộ trước khi lưu.',
        ];
    }

    /**
     * Critical section only: sanitize + UPDATE `articles` row.
     * Keep this free of heavy meta/revision/link work so callers can hold a short DB TX.
     *
     * @param  array<string, mixed>|null  $editorDocument  Canonical TipTap envelope (Phase 5A)
     */
    public function writeArticleRow(
        SeoArticle $article,
        ArticleEditorSaveContext $context,
        string $html,
        ?array $editorDocument = null,
        ?string $expectedEditorDocumentHash = null,
    ): string {
        if ($this->contentLifecycle->shouldRejectEmptyPersist($article, $html)) {
            return (string) ($article->body ?? '');
        }

        $derivedFromJson = false;

        $previousBody = (string) ($article->getOriginal('body') ?? $article->body ?? '');
        $clientHtml = $html;

        if (
            $this->documentWriter->persistenceEnabled()
            && is_array($editorDocument)
            && $editorDocument !== []
        ) {
            try {
                $prepared = $this->documentWriter->applyCanonicalFields(
                    $article,
                    $editorDocument,
                    $expectedEditorDocumentHash,
                );
                $html = $prepared['html'];
                $derivedFromJson = true;

                // Empty-table JSON must not wipe real tables still present in client/body HTML.
                if (
                    $this->htmlHasTableCells($clientHtml)
                    && ! $this->htmlHasTableCells($html)
                ) {
                    $html = $clientHtml;
                } elseif (
                    $this->htmlHasTableCells($previousBody)
                    && ! $this->htmlHasTableCells($html)
                ) {
                    $html = $previousBody;
                }
            } catch (ArticleEditorDocumentException $exception) {
                throw $exception;
            }
        }

        $html = $this->htmlSanitize->stripTransientEditorMarkup($html);
        $html = $this->guardArticleBodyBeforeSave($article, $html);

        $faqSync = $this->faqBodySync->extractFromBodyWhenMissing($article, $html);
        $html = $faqSync['body_html'];

        // Body-only writers without JSON: mark existing JSON stale.
        if (! $derivedFromJson && $this->documentWriter->columnsReady($article)) {
            $this->documentWriter->invalidateForLegacyBodyWrite($article, 'persist_body_only');
        }

        $slug = $context->normalizedSlug();
        $publishAt = $context->resolvePublishAtForSave();
        $postType = SeoProjectTask::normalizePostType($context->postType);

        $payload = [
            'title' => trim($context->title),
            'slug' => $slug !== '' ? $slug : null,
            'status' => $context->status,
            'published_at' => $publishAt,
            'body' => $html,
            'user_id' => auth()->id(),
        ];

        if ($derivedFromJson && $this->documentWriter->columnsReady($article)) {
            $payload['editor_document'] = $article->editor_document;
            $payload['editor_document_schema_version'] = $article->editor_document_schema_version;
            $payload['editor_document_hash'] = $article->editor_document_hash;
            $payload['editor_document_status'] = $article->editor_document_status;
            $payload['editor_document_updated_at'] = $article->editor_document_updated_at;
        } elseif ($this->documentWriter->columnsReady($article) && $article->isDirty('editor_document_status')) {
            $payload['editor_document_status'] = $article->editor_document_status;
        }

        $article->update($payload);

        // Prefer explicit page content_type from editor context when present.
        $classification = ArticleContentClassification::fromTaskPostType($postType);
        if (strtolower(trim((string) $context->postType)) === 'page') {
            $classification = ArticleContentClassification::fromTaskPostType('page');
        } elseif (ArticlePostTypeResolver::isPage($article) && $postType === SeoProjectTask::POST_TYPE_ARTICLE) {
            // Keep page when editor still sends legacy article label.
            $classification['content_type'] = \Omnichannel\Addons\Content\Enums\ContentType::Page;
            $classification['wp_post_type'] = 'page';
        }
        ArticleContentClassification::persist($article, $classification);

        if (class_exists(\Omnichannel\Addons\Publishing\Services\PublishingArticleStateWriter::class)) {
            app(\Omnichannel\Addons\Publishing\Services\PublishingArticleStateWriter::class)->upsert($article, [
                'publication_status' => $context->status,
                'published_at' => $publishAt,
            ]);
        }

        return $html;
    }

    /**
     * Post-row side effects — must run outside the short article-row transaction.
     */
    public function runAfterPersistSideEffects(
        SeoArticle $article,
        ArticleEditorSaveContext $context,
        string $html,
    ): void {
        $articleId = (int) $article->getKey();
        $slug = $context->normalizedSlug();
        $publishAt = $context->resolvePublishAtForSave();

        LocalArticleSaveTimer::measure($articleId, 'runAfterPersistSideEffects.total', function () use (
            $article,
            $context,
            $html,
            $slug,
            $publishAt,
            $articleId,
        ): void {
            LocalArticleSaveTimer::measure(
                $articleId,
                'syncContentProjectScheduledPublish',
                fn () => $this->syncContentProjectScheduledPublish($article->fresh() ?? $article, $context->status, $publishAt),
            );

            LocalArticleSaveTimer::measure(
                $articleId,
                'postImages.syncFromHtml',
                fn () => $this->postImages->syncFromHtml($article, $html),
            );
            $article->refresh();

            $this->syncFlags->markLocalEditPending($article);

            LocalArticleSaveTimer::measure(
                $articleId,
                'revisions.captureAfterSave',
                fn () => $this->revisions->captureAfterSave(
                    $article->fresh(),
                    trim($context->title),
                    $html,
                    [
                        'seo_title' => trim($context->title),
                        'meta_description' => trim($context->seoMetaDescription),
                        'focus_keyword' => trim($context->focusKeyword),
                        'seo_score' => $article->seoProfile?->seo_score !== null ? (float) $article->seoProfile->seo_score : null,
                        'slug' => $slug,
                        'editor_document' => is_array($article->editor_document) ? $article->editor_document : null,
                        'editor_document_schema_version' => (int) ($article->editor_document_schema_version ?? 0) ?: null,
                        'editor_document_hash' => (string) ($article->editor_document_hash ?? '') ?: null,
                        'document_version' => max(1, (int) ($article->document_version ?? 1)),
                    ],
                    auth()->id() !== null ? (int) auth()->id() : null,
                ),
            );

            LocalArticleSaveTimer::measure(
                $articleId,
                'keywordLinks.reconcileForArticle',
                fn () => $this->keywordLinks->reconcileForArticle($article->fresh(), $html),
            );
        });
    }

    private function guardArticleBodyBeforeSave(SeoArticle $article, string $html): string
    {
        $html = trim($html);
        if (strlen($html) >= 200) {
            return $html;
        }

        $existingBody = trim((string) ($article->body ?? ''));
        if (strlen($existingBody) >= 200) {
            return $existingBody;
        }

        $article->loadMissing('articleMetas');
        $wpCached = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', 'wp_post_content')?->meta_value ?? ''));

        if (strlen($wpCached) >= 200) {
            return $wpCached;
        }

        return $html;
    }

    private function htmlHasTableCells(string $html): bool
    {
        return preg_match('/<table\b[^>]*>[\s\S]*?<(td|th)\b/i', $html) === 1;
    }

    private function articleHadSubstantialContent(SeoArticle $article): bool
    {
        if (trim((string) ($article->body ?? '')) !== '') {
            return true;
        }

        $article->loadMissing('articleMetas');
        $cached = trim((string) ($article->articleMetas
            ->where('meta_key', 'wp_post_content')
            ->value('meta_value') ?? ''));

        if (strlen($cached) >= 200) {
            return true;
        }

        return $article->headings()->exists();
    }

    private function syncContentProjectScheduledPublish(
        SeoArticle $article,
        string $status,
        mixed $publishAt,
    ): void {
        $task = $this->contentProjectMembership->activeTaskForArticle($article);
        if (! $task instanceof SeoProjectTask) {
            return;
        }

        // Workflow AI persist runs while task is writing — never mirror schedule/unschedule
        // through ContentProjectItemActionGuard (Schedule blocked: «Generation is running»).
        $taskStatus = strtolower(trim((string) ($task->status ?? '')));
        if (in_array($taskStatus, [
            SeoProjectTask::STATUS_WRITING,
            SeoProjectTask::STATUS_PENDING,
            SeoProjectTask::STATUS_PROCESSING,
        ], true)) {
            return;
        }

        $project = SeoProject::query()->find((int) $task->project_id);
        if (! $project instanceof SeoProject) {
            return;
        }

        $taskId = (int) $task->id;

        // Schedule mirror qua Publishing Queue service (không stamp model ad-hoc).
        try {
            if ($status === 'scheduled' && $publishAt !== null) {
                $at = $publishAt instanceof Carbon ? $publishAt : Carbon::parse((string) $publishAt);
                $this->publishingQueue->schedule($project, [$taskId], $at);

                return;
            }

            if ($task->scheduled_publish_at !== null && $status !== 'scheduled') {
                $this->publishingQueue->unschedule($project, [$taskId]);
            }
        } catch (\RuntimeException) {
            // Fail-soft: content persist must not fail because schedule eligibility rejects.
        }
    }
}
