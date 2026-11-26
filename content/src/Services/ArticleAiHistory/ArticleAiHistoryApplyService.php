<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleAiHistory;

use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\ContentProjects\Enums\WorkflowArtifactType;
use Omnichannel\Addons\ContentProjects\Services\Workflow\ArtifactReusePolicy;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoArticleAiHistoryApply;
use Omnichannel\Addons\Content\Services\ArticleContentFaqService;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleDocumentVersionService;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionException;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionService;
use Omnichannel\Addons\Content\Services\ArticleEditorPersistService;
use Omnichannel\Addons\Content\Services\ArticleOutlineResolver;
use Omnichannel\Addons\Content\Services\SeoArticleRevisionService;
use Omnichannel\Addons\Content\Support\ArticleEditorSaveContext;
use Omnichannel\Addons\Content\Support\ArticleWordPressPostType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Apply artifact AI (outline/content).
 *
 * Outline Apply → pending draft; official meta persisted on Save via commitPendingOnSave.
 * Content Apply → Versioned Save: baseline revision(s) + Markdown parse + canonical body Save
 * + committed History row. Does not touch Planning Outline (seo_article_outline*).
 *
 * Never calls AI, never mutates generation/run/workflow, never publishes WordPress.
 */
final class ArticleAiHistoryApplyService
{
    public function __construct(
        private readonly ArticleAiHistoryListService $listService,
        private readonly ArticleAiHistoryPendingDraftStore $draftStore,
        private readonly ArticleOutlineResolver $outlineResolver,
        private readonly ArtifactReusePolicy $reusePolicy,
        private readonly ArticleContentFaqService $contentFaq,
        private readonly SeoArticleRevisionService $revisions,
        private readonly ArticleEditorPersistService $persist,
        private readonly ArticleDocumentVersionService $documentVersions,
    ) {}

    /**
     * @param  list<int>  $accessibleProjectIds
     */
    public function preview(SeoArticle $article, string $artifactRef, array $accessibleProjectIds): ArticleAiHistoryActionResult
    {
        $articleId = (int) $article->getKey();

        $artifact = $this->listService->resolveOwnedArtifact($article, $artifactRef, $accessibleProjectIds);
        if ($artifact === null) {
            return ArticleAiHistoryActionResult::fail(
                'artifact_not_found',
                'Không tìm thấy nội dung AI này trong lịch sử bài viết.',
                $articleId,
            );
        }

        if ((bool) ($artifact['is_deleted'] ?? false)) {
            return ArticleAiHistoryActionResult::fail(
                'artifact_deleted',
                'Nội dung này đã bị xoá khỏi lịch sử.',
                $articleId,
                ['artifact_id' => $artifactRef],
            );
        }

        $payload = (string) ($artifact['normalized_artifact'] ?? '');
        if (trim($payload) === '') {
            $payload = $this->loadOutputPayload($artifact);
        }
        $summary = $this->buildPreviewSummary($payload, $artifact['artifact_type'] ?? null);

        return ArticleAiHistoryActionResult::ok(
            'preview_ready',
            'Xem trước nội dung AI.',
            $articleId,
            [
                'artifact_id' => $artifactRef,
                'artifact_type' => $artifact['artifact_type'] ?? null,
                'run_id' => $artifact['run_id'] ?? null,
                'attempt' => $artifact['attempt'] ?? null,
                'article_id' => $articleId,
                'created_at' => $artifact['ran_at'] ?? null,
                'section_count' => $summary['section_count'],
                'word_count' => $summary['word_count'],
                'preview_text' => $summary['preview_text'],
            ],
        );
    }

    /**
     * @param  list<int>  $accessibleProjectIds
     */
    public function applyOutline(
        SeoArticle $article,
        string $artifactRef,
        array $accessibleProjectIds,
        int $userId,
        bool $confirmDirty = false,
    ): ArticleAiHistoryActionResult {
        return $this->applyOutlinePending($article, $artifactRef, $accessibleProjectIds, $userId, $confirmDirty);
    }

    /**
     * Versioned Content Apply: validate → revise → parse → canonical Save → commit History.
     *
     * @param  list<int>  $accessibleProjectIds
     * @param  string|null  $editorHtml  Optional unsaved editor HTML from the browser (never guessed from DB).
     */
    public function applyContent(
        SeoArticle $article,
        string $artifactRef,
        array $accessibleProjectIds,
        int $userId,
        bool $confirmDirty = false,
        ?string $editorHtml = null,
        int|string|null $expectedDocumentVersion = null,
    ): ArticleAiHistoryActionResult {
        return $this->applyContentVersioned(
            $article,
            $artifactRef,
            $accessibleProjectIds,
            $userId,
            $confirmDirty,
            $editorHtml,
            $expectedDocumentVersion,
        );
    }

    public function undoPending(SeoArticle $article, int $userId): ArticleAiHistoryActionResult
    {
        $articleId = (int) $article->getKey();
        $pending = $this->draftStore->get($article);

        if ($pending === null) {
            return ArticleAiHistoryActionResult::fail(
                'no_pending_draft',
                'Không có bản nháp áp dụng AI để hoàn tác.',
                $articleId,
                ['draft_dirty' => false],
            );
        }

        $this->draftStore->clear($article);

        return ArticleAiHistoryActionResult::ok(
            'pending_draft_cleared',
            'Đã hoàn tác bản nháp áp dụng AI.',
            $articleId,
            [
                'artifact_id' => $pending['artifact_ref'] ?? null,
                'article_id' => $articleId,
                'action' => 'undo_pending',
                'applied_target' => $pending['target'] ?? null,
                'draft_dirty' => false,
            ],
        );
    }

    /**
     * Outline pending → official meta on Save. Content Apply is versioned elsewhere;
     * leftover content pending may still commit here if user Saves manually.
     */
    public function commitPendingOnSave(SeoArticle $article, int $userId): ArticleAiHistoryActionResult
    {
        $articleId = (int) $article->getKey();
        $pending = $this->draftStore->get($article);

        if ($pending === null) {
            return ArticleAiHistoryActionResult::ok(
                'no_pending_draft',
                'Không có bản nháp AI cần lưu.',
                $articleId,
                ['draft_dirty' => false],
            );
        }

        $artifactRef = trim((string) ($pending['artifact_ref'] ?? ''));
        $target = trim((string) ($pending['target'] ?? ''));
        $payload = trim((string) ($pending['payload'] ?? ''));

        if ($target === 'outline' && $payload !== '') {
            $persisted = $this->outlineResolver->persist($article, $payload);
            if (! ($persisted['ok'] ?? false)) {
                return ArticleAiHistoryActionResult::fail(
                    'outline_persist_failed',
                    (string) ($persisted['message'] ?? 'Không lưu được dàn ý từ bản nháp AI.'),
                    $articleId,
                    [
                        'artifact_id' => $artifactRef !== '' ? $artifactRef : null,
                        'draft_dirty' => true,
                    ],
                );
            }
        }

        if ($artifactRef !== '') {
            $applyRow = SeoArticleAiHistoryApply::query()
                ->where('article_id', $articleId)
                ->where('artifact_ref', $artifactRef)
                ->where('committed', false)
                ->orderByDesc('id')
                ->first();

            if ($applyRow instanceof SeoArticleAiHistoryApply) {
                $existingProvenance = is_array($applyRow->provenance) ? $applyRow->provenance : [];
                $applyRow->committed = true;
                $applyRow->provenance = array_merge(
                    $existingProvenance,
                    is_array($pending['provenance'] ?? null) ? $pending['provenance'] : [],
                    [
                        'committed_by' => $userId,
                        'committed_at' => now()->toIso8601String(),
                        'apply_mode' => 'manual_debug_apply',
                    ],
                );
                $applyRow->save();
            }
        }

        $this->draftStore->clear($article);

        return ArticleAiHistoryActionResult::ok(
            'pending_draft_committed',
            'Đã lưu nội dung AI vào bài viết.',
            $articleId,
            [
                'artifact_id' => $artifactRef !== '' ? $artifactRef : null,
                'article_id' => $articleId,
                'action' => 'commit_pending',
                'applied_target' => $target !== '' ? $target : null,
                'draft_dirty' => false,
                'provenance' => $pending['provenance'] ?? null,
            ],
        );
    }

    /**
     * @param  list<int>  $accessibleProjectIds
     */
    private function applyContentVersioned(
        SeoArticle $article,
        string $artifactRef,
        array $accessibleProjectIds,
        int $userId,
        bool $confirmDirty,
        ?string $editorHtml,
        int|string|null $expectedDocumentVersion,
    ): ArticleAiHistoryActionResult {
        $articleId = (int) $article->getKey();

        try {
            app(ArticleEditorSessionService::class)
                ->assertNoActiveEditorSession($article, 'ai_apply_content');
        } catch (ArticleEditorSessionException $exception) {
            return ArticleAiHistoryActionResult::fail(
                $exception->errorCode,
                $exception->getMessage(),
                $articleId,
                [
                    'lock' => $exception->context['lock'] ?? null,
                    'operation' => 'ai_apply_content',
                ],
            );
        }

        $artifact = $this->listService->resolveOwnedArtifact($article, $artifactRef, $accessibleProjectIds);
        if ($artifact === null) {
            return ArticleAiHistoryActionResult::fail(
                'artifact_not_found',
                'Không tìm thấy nội dung AI này trong lịch sử bài viết.',
                $articleId,
            );
        }

        if ((bool) ($artifact['is_deleted'] ?? false)) {
            return ArticleAiHistoryActionResult::fail(
                'artifact_deleted',
                'Nội dung này đã bị xoá khỏi lịch sử, không thể áp dụng.',
                $articleId,
                ['artifact_id' => $artifactRef],
            );
        }

        $expectedType = WorkflowArtifactType::ArticleContent->value;
        if (($artifact['artifact_type'] ?? null) !== $expectedType || ! (bool) ($artifact['can_apply_content'] ?? false)) {
            return ArticleAiHistoryActionResult::fail(
                'artifact_type_mismatch',
                'Nội dung này không hợp lệ để áp dụng làm nội dung bài viết.',
                $articleId,
                ['artifact_id' => $artifactRef],
            );
        }

        $existingPending = $this->draftStore->get($article);
        if ($existingPending !== null && ! $confirmDirty) {
            $samePending = trim((string) ($existingPending['artifact_ref'] ?? '')) === $artifactRef
                && trim((string) ($existingPending['target'] ?? '')) === 'content';
            if (! $samePending) {
                return ArticleAiHistoryActionResult::fail(
                    'requires_dirty_confirm',
                    'Đang có bản nháp áp dụng AI chưa lưu. Xác nhận để ghi đè bản nháp này.',
                    $articleId,
                    [
                        'artifact_id' => $artifactRef,
                        'pending_draft' => $existingPending,
                        'draft_dirty' => true,
                    ],
                );
            }
        }

        $rawPayload = (string) ($artifact['normalized_artifact'] ?? '');
        if (trim($rawPayload) === '') {
            $rawPayload = $this->loadOutputPayload($artifact);
        }
        if (trim($rawPayload) === '') {
            return ArticleAiHistoryActionResult::fail(
                'artifact_payload_empty',
                'Nội dung AI rỗng, không thể áp dụng.',
                $articleId,
                ['artifact_id' => $artifactRef],
            );
        }

        if ($this->reusePolicy->looksLikeOutlineMarkerPayload($rawPayload)
            || ! $this->reusePolicy->isValidArticleContentPayload($rawPayload)
        ) {
            return ArticleAiHistoryActionResult::fail(
                'artifact_payload_invalid',
                'Output có marker dàn ý / không phải article_content hợp lệ — không ghi vào body.',
                $articleId,
                ['artifact_id' => $artifactRef],
            );
        }

        $prepared = $this->contentFaq->prepareEditorHtmlFromAiOutput($rawPayload);
        $html = trim((string) ($prepared['html'] ?? ''));
        if ($html === '') {
            return ArticleAiHistoryActionResult::fail(
                'artifact_payload_invalid',
                'Không chuyển được output sang HTML editor hợp lệ — không ghi vào body.',
                $articleId,
                ['artifact_id' => $artifactRef],
            );
        }

        $article->loadMissing(['articleMetas', 'wordpressLink', 'seoProfile', 'publishingState']);

        try {
            $this->documentVersions->assertExpected($article, $expectedDocumentVersion, [
                'operation' => 'ai_apply_content',
            ]);
        } catch (ArticleEditorSessionException $exception) {
            return ArticleAiHistoryActionResult::fail(
                $exception->errorCode,
                $exception->getMessage(),
                $articleId,
                array_merge(
                    [
                        'artifact_id' => $artifactRef,
                        'operation' => 'ai_apply_content',
                    ],
                    $exception->context,
                ),
            );
        }

        $savedBody = trim((string) ($article->body ?? ''));
        $pendingAiHtml = '';
        if (is_array($existingPending)
            && trim((string) ($existingPending['target'] ?? '')) === 'content'
            && trim((string) ($existingPending['payload_format'] ?? '')) === 'editor_html'
        ) {
            $pendingAiHtml = trim((string) ($existingPending['payload'] ?? ''));
        }

        $clientHtml = trim((string) ($editorHtml ?? ''));
        $importMeta = [
            'source' => $prepared['source'] ?? 'markdown',
            'meta_description' => $prepared['meta_description'] ?? null,
            'h1_title' => $prepared['h1_title'] ?? null,
            'faq_count' => is_array($prepared['faqs'] ?? null) ? count($prepared['faqs']) : 0,
        ];

        $provenance = [
            'artifact_ref' => $artifactRef,
            'artifact_type' => $expectedType,
            'run_id' => $artifact['run_id'] ?? null,
            'run_item_id' => $artifact['run_item_id'] ?? null,
            'attempt' => $artifact['attempt'] ?? null,
            'classification' => $artifact['classification'] ?? null,
            'hook_key' => $artifact['hook_key'] ?? null,
            'content_import' => $importMeta,
            'apply_mode' => 'versioned_save',
        ];

        $operationId = (string) Str::uuid();
        $seoMetaBase = [
            'seo_title' => trim((string) ($article->title ?? '')),
            'meta_description' => trim((string) (
                $article->articleMetas->firstWhere('meta_key', 'seo_meta_description')?->meta_value
                ?? $article->articleMetas->firstWhere('meta_key', 'meta_description')?->meta_value
                ?? ''
            )),
            'focus_keyword' => trim((string) (
                $article->articleMetas->firstWhere('meta_key', 'seo_focus_keyword')?->meta_value ?? ''
            )),
            'slug' => trim((string) ($article->slug ?? '')),
            'seo_score' => $article->seoProfile?->seo_score !== null ? (float) $article->seoProfile->seo_score : null,
            'artifact_ref' => $artifactRef,
        ];

        $planningOutlineBefore = trim((string) (
            $article->articleMetas->firstWhere('meta_key', ArticleOutlineResolver::META_KEY)?->meta_value ?? ''
        ));

        try {
            $resultMeta = DB::connection('omi_seo_ai')->transaction(function () use (
                $article,
                $articleId,
                $artifact,
                $artifactRef,
                $expectedType,
                $userId,
                $html,
                $savedBody,
                $clientHtml,
                $pendingAiHtml,
                $seoMetaBase,
                $provenance,
                $operationId,
                $prepared,
            ): array {
                $revisionIds = [];

                if ($savedBody !== '') {
                    $baseline = $this->revisions->ensureBaselineBeforeAiApply(
                        $article,
                        $savedBody,
                        trim((string) ($article->title ?? '')),
                        array_merge($seoMetaBase, ['ai_apply_baseline' => true, 'source' => 'saved_body']),
                        $userId,
                    );
                    if ($baseline !== null) {
                        $revisionIds['saved_baseline_id'] = (int) $baseline->id;
                    }
                }

                // Unsaved editor HTML only when browser supplies it and it is distinct
                // from saved body, parsed AI HTML, and existing AI pending draft.
                if ($this->isDistinctUnsavedEditorHtml($clientHtml, $savedBody, $html, $pendingAiHtml)) {
                    $unsaved = $this->revisions->ensureBaselineBeforeAiApply(
                        $article,
                        $clientHtml,
                        trim((string) ($article->title ?? '')),
                        array_merge($seoMetaBase, [
                            'ai_apply_baseline' => true,
                            'source' => 'unsaved_editor',
                        ]),
                        $userId,
                    );
                    if ($unsaved !== null) {
                        $revisionIds['unsaved_editor_revision_id'] = (int) $unsaved->id;
                    }
                }

                $metaDescription = trim((string) ($prepared['meta_description'] ?? ''));
                if ($metaDescription !== '') {
                    foreach (['seo_meta_description', 'meta_description'] as $key) {
                        $article->articleMetas()->updateOrCreate(
                            ['meta_key' => $key],
                            ['meta_value' => $metaDescription],
                        );
                    }
                }

                $context = ArticleEditorSaveContext::fromBundle($article, [
                    'article_meta' => [
                        'title' => trim((string) ($article->title ?? '')),
                        'slug' => trim((string) ($article->slug ?? '')),
                        'seo_meta_description' => $metaDescription !== ''
                            ? $metaDescription
                            : (string) ($seoMetaBase['meta_description'] ?? ''),
                        'focus_keyword' => (string) ($seoMetaBase['focus_keyword'] ?? ''),
                    ],
                    'publish_box' => [
                        'post_type' => ArticleWordPressPostType::resolve($article),
                        'status' => (string) ($article->status ?? 'draft'),
                        'visibility' => 'public',
                    ],
                ]);

                $writtenHtml = $this->persist->writeArticleRow($article, $context, $html);
                $fresh = $article->fresh() ?? $article;
                $this->persist->runAfterPersistSideEffects($fresh, $context, $writtenHtml);
                $fresh = $fresh->fresh() ?? $fresh;

                // WP-linked Save skips local revision unless forced; dedupe avoids doubles.
                if (! $this->revisions->hasRevisionWithContent($fresh, $writtenHtml)) {
                    $savedRevision = $this->revisions->captureAfterSave(
                        $fresh,
                        trim((string) ($fresh->title ?? '')),
                        $writtenHtml,
                        array_merge($seoMetaBase, [
                            'ai_apply_save' => true,
                            'meta_description' => $metaDescription !== ''
                                ? $metaDescription
                                : (string) ($seoMetaBase['meta_description'] ?? ''),
                            'document_version' => max(1, (int) ($fresh->document_version ?? 1)),
                        ]),
                        $userId,
                        force: true,
                    );
                    if ($savedRevision !== null) {
                        $revisionIds['ai_saved_revision_id'] = (int) $savedRevision->id;
                    }
                }

                $applyProvenance = array_merge($provenance, $revisionIds, [
                    'committed_by' => $userId,
                    'committed_at' => now()->toIso8601String(),
                    'operation_id' => $operationId,
                ]);

                SeoArticleAiHistoryApply::query()->create([
                    'article_id' => $articleId,
                    'artifact_ref' => $artifactRef,
                    'prompt_result_id' => $artifact['result_id'] ?? null,
                    'artifact_type' => $expectedType,
                    'run_id' => $artifact['run_id'] ?? null,
                    'run_item_id' => $artifact['run_item_id'] ?? null,
                    'attempt' => $artifact['attempt'] ?? null,
                    'applied_by' => $userId,
                    'applied_at' => now(),
                    'target' => 'content',
                    'apply_mode' => 'versioned_save',
                    'committed' => true,
                    'provenance' => $applyProvenance,
                ]);

                // Clear any prior pending draft only after successful persistence.
                $this->draftStore->clear($fresh);

                return [
                    'written_html' => $writtenHtml,
                    'provenance' => $applyProvenance,
                    'document_version' => max(1, (int) ($fresh->document_version ?? 1)),
                ];
            });
        } catch (Throwable $exception) {
            return ArticleAiHistoryActionResult::fail(
                'content_apply_save_failed',
                'Không lưu được nội dung AI vào bài viết: '.$exception->getMessage(),
                $articleId,
                [
                    'artifact_id' => $artifactRef,
                    'draft_dirty' => $existingPending !== null,
                    'operation_id' => $operationId,
                ],
            );
        }

        $article->unsetRelation('articleMetas');
        $article->load('articleMetas');
        $planningOutlineAfter = trim((string) (
            $article->articleMetas->firstWhere('meta_key', ArticleOutlineResolver::META_KEY)?->meta_value ?? ''
        ));

        return ArticleAiHistoryActionResult::ok(
            'content_apply_saved',
            'Đã áp dụng và lưu vào bài viết.',
            $articleId,
            [
                'artifact_id' => $artifactRef,
                'artifact_type' => $expectedType,
                'run_id' => $artifact['run_id'] ?? null,
                'attempt' => $artifact['attempt'] ?? null,
                'article_id' => $articleId,
                'action' => 'apply_content',
                'applied_target' => 'content',
                'draft_dirty' => false,
                'saved' => true,
                'provenance' => $resultMeta['provenance'] ?? $provenance,
                'operation_id' => $operationId,
                'document_version' => $resultMeta['document_version'] ?? null,
                'planning_outline_unchanged' => $planningOutlineBefore === $planningOutlineAfter,
            ],
        );
    }

    /**
     * @param  list<int>  $accessibleProjectIds
     */
    private function applyOutlinePending(
        SeoArticle $article,
        string $artifactRef,
        array $accessibleProjectIds,
        int $userId,
        bool $confirmDirty,
    ): ArticleAiHistoryActionResult {
        $articleId = (int) $article->getKey();
        $target = 'outline';

        try {
            app(ArticleEditorSessionService::class)
                ->assertNoActiveEditorSession($article, 'ai_apply_outline');
        } catch (ArticleEditorSessionException $exception) {
            return ArticleAiHistoryActionResult::fail(
                $exception->errorCode,
                $exception->getMessage(),
                $articleId,
                [
                    'lock' => $exception->context['lock'] ?? null,
                    'operation' => 'ai_apply_outline',
                ],
            );
        }

        $artifact = $this->listService->resolveOwnedArtifact($article, $artifactRef, $accessibleProjectIds);
        if ($artifact === null) {
            return ArticleAiHistoryActionResult::fail(
                'artifact_not_found',
                'Không tìm thấy nội dung AI này trong lịch sử bài viết.',
                $articleId,
            );
        }

        if ((bool) ($artifact['is_deleted'] ?? false)) {
            return ArticleAiHistoryActionResult::fail(
                'artifact_deleted',
                'Nội dung này đã bị xoá khỏi lịch sử, không thể áp dụng.',
                $articleId,
                ['artifact_id' => $artifactRef],
            );
        }

        $expectedType = WorkflowArtifactType::ArticleOutline->value;
        if (($artifact['artifact_type'] ?? null) !== $expectedType || ! (bool) ($artifact['can_apply_outline'] ?? false)) {
            return ArticleAiHistoryActionResult::fail(
                'artifact_type_mismatch',
                'Nội dung này không hợp lệ để áp dụng làm dàn ý.',
                $articleId,
                ['artifact_id' => $artifactRef],
            );
        }

        $existingPending = $this->draftStore->get($article);
        if ($existingPending !== null && ! $confirmDirty) {
            $samePending = trim((string) ($existingPending['artifact_ref'] ?? '')) === $artifactRef
                && trim((string) ($existingPending['target'] ?? '')) === 'outline';
            if (! $samePending) {
                return ArticleAiHistoryActionResult::fail(
                    'requires_dirty_confirm',
                    'Đang có bản nháp áp dụng AI chưa lưu. Xác nhận để ghi đè bản nháp này.',
                    $articleId,
                    [
                        'artifact_id' => $artifactRef,
                        'pending_draft' => $existingPending,
                        'draft_dirty' => true,
                    ],
                );
            }
        }

        $payload = (string) ($artifact['normalized_artifact'] ?? '');
        if (trim($payload) === '') {
            $payload = $this->loadOutputPayload($artifact);
        }
        if (trim($payload) === '') {
            return ArticleAiHistoryActionResult::fail(
                'artifact_payload_empty',
                'Nội dung AI rỗng, không thể áp dụng.',
                $articleId,
                ['artifact_id' => $artifactRef],
            );
        }

        if (! $this->outlineResolver->isUsable($payload)) {
            return ArticleAiHistoryActionResult::fail(
                'artifact_payload_invalid',
                'Dàn ý không parse được — không thể áp dụng.',
                $articleId,
                ['artifact_id' => $artifactRef],
            );
        }

        $previous = $this->draftStore->snapshotPrevious($article, $target);
        $operationId = (string) Str::uuid();
        $provenance = [
            'artifact_ref' => $artifactRef,
            'artifact_type' => $expectedType,
            'run_id' => $artifact['run_id'] ?? null,
            'run_item_id' => $artifact['run_item_id'] ?? null,
            'attempt' => $artifact['attempt'] ?? null,
            'classification' => $artifact['classification'] ?? null,
            'hook_key' => $artifact['hook_key'] ?? null,
        ];

        $draft = [
            'article_id' => $articleId,
            'artifact_ref' => $artifactRef,
            'artifact_type' => $expectedType,
            'run_id' => $artifact['run_id'] ?? null,
            'run_item_id' => $artifact['run_item_id'] ?? null,
            'attempt' => $artifact['attempt'] ?? null,
            'target' => $target,
            'apply_mode' => 'manual_debug_apply',
            'payload' => $payload,
            'payload_format' => 'outline_markdown',
            'previous_payload' => $previous['previous_outline'] ?? '',
            'applied_by' => $userId,
            'applied_at' => now()->toIso8601String(),
            'provenance' => $provenance,
            'operation_id' => $operationId,
        ];

        $this->draftStore->put($article, $draft);

        SeoArticleAiHistoryApply::query()->create([
            'article_id' => $articleId,
            'artifact_ref' => $artifactRef,
            'prompt_result_id' => $artifact['result_id'] ?? null,
            'artifact_type' => $expectedType,
            'run_id' => $artifact['run_id'] ?? null,
            'run_item_id' => $artifact['run_item_id'] ?? null,
            'attempt' => $artifact['attempt'] ?? null,
            'applied_by' => $userId,
            'applied_at' => now(),
            'target' => $target,
            'apply_mode' => 'manual_debug_apply',
            'committed' => false,
            'provenance' => $provenance,
        ]);

        return ArticleAiHistoryActionResult::ok(
            'outline_apply_pending',
            'Đã áp dụng dàn ý vào bản nháp editor. Lưu bài viết để giữ thay đổi.',
            $articleId,
            [
                'artifact_id' => $artifactRef,
                'artifact_type' => $expectedType,
                'run_id' => $artifact['run_id'] ?? null,
                'attempt' => $artifact['attempt'] ?? null,
                'article_id' => $articleId,
                'action' => 'apply_outline',
                'applied_target' => $target,
                'draft_dirty' => true,
                'provenance' => $provenance,
                'operation_id' => $operationId,
                'pending_draft' => $draft,
            ],
        );
    }

    /**
     * True when browser-supplied HTML is a distinct user draft — not saved body,
     * not the AI result being applied, and not an existing AI pending payload.
     */
    public function isDistinctUnsavedEditorHtml(
        string $clientHtml,
        string $savedBody,
        string $aiHtml,
        string $pendingAiHtml = '',
    ): bool {
        $clientHtml = trim($clientHtml);
        if ($clientHtml === '') {
            return false;
        }

        $clientHash = hash('sha256', $clientHtml);
        foreach ([$savedBody, $aiHtml, $pendingAiHtml] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && hash('sha256', $candidate) === $clientHash) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{section_count: ?int, word_count: int, preview_text: string}
     */
    private function buildPreviewSummary(string $payload, ?string $artifactType): array
    {
        if ($artifactType === WorkflowArtifactType::ArticleOutline->value) {
            $sectionCount = preg_match_all('/^#{1,6}\s+\S+/mu', $payload);
            $plain = trim(strip_tags($payload));

            return [
                'section_count' => $sectionCount !== false ? $sectionCount : 0,
                'word_count' => $plain === '' ? 0 : str_word_count($plain),
                'preview_text' => mb_substr($plain, 0, 500),
            ];
        }

        $plain = trim(strip_tags($this->sanitizeContentHtml($payload)));

        return [
            'section_count' => null,
            'word_count' => $plain === '' ? 0 : str_word_count($plain),
            'preview_text' => mb_substr($plain, 0, 500),
        ];
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    private function loadOutputPayload(array $artifact): string
    {
        $resultId = (int) ($artifact['result_id'] ?? $artifact['prompt_result_id'] ?? 0);
        if ($resultId <= 0) {
            return '';
        }

        $result = PromptResult::query()->find($resultId);
        if (! $result instanceof PromptResult) {
            return '';
        }

        return trim((string) ($result->output_text ?? ''));
    }

    private function sanitizeContentHtml(string $html): string
    {
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;
        $html = preg_replace('#<iframe\b[^>]*>.*?</iframe>#is', '', $html) ?? $html;
        $html = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html) ?? $html;

        return trim($html);
    }
}
