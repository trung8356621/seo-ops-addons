<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Actions\Article;

use Omnichannel\Addons\Agent\Automation\Contracts\BusinessAction;
use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Agent\Automation\Data\ActionDefinition;
use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Enums\ActionRiskLevel;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSelectability;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSideEffect;
use Omnichannel\Addons\Agent\Automation\Support\ActionSupport;
use Omnichannel\Addons\Agent\Automation\Support\ArticleContentConflictGuard;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleEditorPersistService;
use Omnichannel\Addons\Content\Services\ArticleLastSavedTimestampService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectContentManagerHandoffService;
use Omnichannel\Addons\Content\Support\ArticleEditorSaveContext;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Local content update via ArticleEditorPersistService only (not Orchestrator).
 */
final class UpdateArticleContentAction implements BusinessAction
{
    public function __construct(
        private readonly ArticleEditorPersistService $persistService,
        private readonly ArticleContentConflictGuard $conflictGuard,
        private readonly ArticleLastSavedTimestampService $lastSavedTimestamps,
        private readonly ContentProjectContentManagerHandoffService $contentManagerHandoff,
    ) {}

    public static function definition(): ActionDefinition
    {
        return new ActionDefinition(
            key: 'article.content.update',
            name: 'Update article content (local)',
            description: 'Update local article body/title. Must not call WordPress.',
            module: 'article',
            sideEffect: ActionSideEffect::InternalWrite,
            riskLevel: ActionRiskLevel::Medium,
            selectability: ActionSelectability::Selectable,
            inputSchema: [
                'article_id' => ['type' => 'integer', 'required' => true],
                'content' => ['type' => 'string', 'required' => true],
                'title' => ['type' => 'string', 'required' => false],
                'slug' => ['type' => 'string', 'required' => false],
                'status' => ['type' => 'string', 'required' => false],
                'post_type' => ['type' => 'string', 'required' => false],
                'visibility' => ['type' => 'string', 'required' => false],
                'publish_day' => ['type' => 'string', 'required' => false],
                'publish_month' => ['type' => 'string', 'required' => false],
                'publish_year' => ['type' => 'string', 'required' => false],
                'publish_hour' => ['type' => 'string', 'required' => false],
                'publish_minute' => ['type' => 'string', 'required' => false],
                'seo_meta_description' => ['type' => 'string', 'required' => false],
                'focus_keyword' => ['type' => 'string', 'required' => false],
                'expected_updated_at' => ['type' => 'string', 'required' => false],
                'expected_content_hash' => ['type' => 'string', 'required' => false],
                'expected_document_version' => ['type' => 'integer', 'required' => false],
                'editor_document' => ['type' => 'object', 'required' => false],
                'expected_editor_document_hash' => ['type' => 'string', 'required' => false],
                'client_rendered_html' => ['type' => 'string', 'required' => false],
                'force_overwrite' => ['type' => 'boolean', 'required' => false],
            ],
            outputSchema: [
                'article_id' => ['type' => 'integer'],
                'status' => ['type' => 'string'],
                'content_hash' => ['type' => 'string'],
                'document_version' => ['type' => 'integer'],
                'editor_document_hash' => ['type' => 'string'],
            ],
            idempotent: true,
            lockScope: 'article',
            supportsDryRun: true,
            emittedEvents: ['article.content_updated'],
        );
    }

    public function execute(ActionContext $context, array $input): ActionResult
    {
        if ($denied = ActionSupport::assertMutable($context)) {
            return $denied;
        }

        $articleId = (int) ($input['article_id'] ?? 0);
        $article = ActionSupport::findArticle($articleId);
        if ($article === null) {
            return ActionResult::failure('article_not_found', "Article [{$articleId}] not found.");
        }

        $forceOverwrite = SeoAccessControl::canForceArticleContentOverwrite()
            || filter_var($input['force_overwrite'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (! $forceOverwrite) {
            if ($conflict = $this->conflictGuard->assertCompatible($article, $input)) {
                return $conflict;
            }
        }

        $content = (string) ($input['content'] ?? '');
        $title = trim((string) ($input['title'] ?? $article->title ?? ''));
        $slugInput = array_key_exists('slug', $input)
            ? trim((string) $input['slug'])
            : null;

        if ($context->dryRun) {
            return ActionResult::success(
                output: [
                    'article_id' => $articleId,
                    'dry_run' => true,
                    'content_hash' => $this->conflictGuard->contentHash((string) ($article->body ?? '')),
                    'updated_at' => $article->updated_at?->toIso8601String(),
                    'force_overwrite' => $forceOverwrite,
                ],
                status: \Omnichannel\Addons\Agent\Automation\Enums\ActionRunStatus::DryRun,
            );
        }

        $saveContext = ArticleEditorSaveContext::fromBundle($article, $this->buildEditorBundle($article, $title, $slugInput, $input));

        try {
            $result = ActionSupport::withArticleLock($articleId, function () use ($article, $saveContext, $content, $input, $forceOverwrite) {
                return $this->persistUnderShortRowLock($article, $saveContext, $content, $input, $forceOverwrite);
            });
        } catch (ArticleContentConflictException $exception) {
            return $exception->result;
        } catch (\Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentException $exception) {
            return ActionResult::failure($exception->errorCode, $exception->getMessage(), $exception->context);
        } catch (\Throwable $exception) {
            if (trim($exception->getMessage()) === 'article_write_busy') {
                return ActionResult::failure(
                    'article_write_busy',
                    'Bài đang được lưu bởi request khác. Thử lại sau giây lát.',
                );
            }

            return ActionResult::failure('persist_failed', $this->friendlyPersistError($exception));
        }

        if (! ($result['success'] ?? false)) {
            return ActionResult::failure('persist_rejected', (string) ($result['message'] ?? 'Persist failed.'));
        }

        $fresh = $article->fresh();
        if (
            $fresh instanceof SeoArticle
            && $this->lastSavedTimestamps->shouldTouchManualFromOrigin($context->origin)
        ) {
            $this->lastSavedTimestamps->touchManualSaved($fresh);
            $fresh = $fresh->fresh() ?? $fresh;
        }

        $handoff = ['handed_off' => false, 'skipped' => true];
        if ($fresh instanceof SeoArticle) {
            $actor = auth()->user();
            $handoff = $this->contentManagerHandoff->maybeHandoffAfterCanonicalSave(
                $fresh,
                $actor instanceof User ? $actor : null,
                $context->origin,
            );
            if (! empty($handoff['handed_off'])) {
                $fresh = $fresh->fresh() ?? $fresh;
            }
        }

        return ActionResult::success(
            output: [
                'article_id' => $articleId,
                'status' => (string) ($fresh?->status ?? $article->status ?? 'draft'),
                'message' => (string) ($result['message'] ?? ''),
                'content_hash' => $this->conflictGuard->contentHash((string) ($fresh?->body ?? $content)),
                'updated_at' => $fresh?->updated_at?->toIso8601String(),
                'document_version' => max(1, (int) ($fresh?->document_version ?? $article->document_version ?? 1)),
                'editor_document_hash' => (string) ($fresh?->editor_document_hash ?? ''),
                'editor_document_schema_version' => (int) ($fresh?->editor_document_schema_version ?? 0),
                'content_project_handoff' => $handoff,
            ],
            events: [
                ActionSupport::articleEvent('article.content_updated', $context, $articleId, [
                    'changed_fields' => ['content', 'title'],
                ]),
            ],
            changed: ['content', 'title'],
        );
    }

    /**
     * Short TX around article row only; side-effects (images/revision/links) run after commit.
     * Retries InnoDB lock-wait so concurrent Save/Sync không fail cứng sau 50s.
     *
     * @param  array<string, mixed>  $input
     * @return array{success: bool, message: string, html?: string}
     */
    private function persistUnderShortRowLock(
        SeoArticle $article,
        ArticleEditorSaveContext $saveContext,
        string $content,
        array $input,
        bool $forceOverwrite,
    ): array {
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $written = DB::connection('omi_seo_ai')->transaction(function () use ($article, $saveContext, $content, $input, $forceOverwrite): array {
                    $fresh = $article->fresh();
                    if ($fresh === null) {
                        throw new \RuntimeException('Article disappeared during lock.');
                    }
                    if (! $forceOverwrite) {
                        if ($conflict = $this->conflictGuard->assertCompatible($fresh, $input)) {
                            throw new ArticleContentConflictException($conflict);
                        }
                    }

                    $editorDocument = is_array($input['editor_document'] ?? null) ? $input['editor_document'] : null;
                    $expectedDocHash = isset($input['expected_editor_document_hash'])
                        ? (string) $input['expected_editor_document_hash']
                        : null;
                    $html = $this->persistService->writeArticleRow(
                        $fresh,
                        $saveContext,
                        $content,
                        $editorDocument,
                        $expectedDocHash !== '' ? $expectedDocHash : null,
                    );

                    return [
                        'article' => $fresh->fresh() ?? $fresh,
                        'html' => $html,
                    ];
                });

                /** @var SeoArticle $persistedArticle */
                $persistedArticle = $written['article'];
                $html = (string) $written['html'];

                $this->persistService->runAfterPersistSideEffects($persistedArticle, $saveContext, $html);

                return $this->persistService->buildPersistResult(
                    $persistedArticle->fresh() ?? $persistedArticle,
                    $html,
                );
            } catch (ArticleContentConflictException $exception) {
                throw $exception;
            } catch (QueryException $exception) {
                if ($attempt < $maxAttempts && $this->isLockWaitTimeout($exception)) {
                    usleep(150_000 * $attempt);

                    continue;
                }

                throw $exception;
            }
        }

        throw new \RuntimeException('Could not persist article after lock-wait retries.');
    }

    private function isLockWaitTimeout(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = $exception->getMessage();

        return $driverCode === 1205
            || ($sqlState === 'HY000' && str_contains($message, 'Lock wait timeout'))
            || str_contains($message, 'SQLSTATE[40001]')
            || str_contains($message, 'Deadlock found');
    }

    private function friendlyPersistError(\Throwable $exception): string
    {
        if ($exception instanceof QueryException && $this->isLockWaitTimeout($exception)) {
            return 'Bài đang bị khóa bởi thao tác database khác. Đợi vài giây rồi thử lại.';
        }

        $message = trim($exception->getMessage());
        if ($message === 'article_write_busy' || $message === 'Could not acquire article automation lock.') {
            return 'Bài đang được lưu bởi request khác. Thử lại sau giây lát.';
        }

        return $message !== '' ? $message : 'Không lưu được bài viết.';
    }

    /**
     * Build ArticleEditorSaveContext bundle từ input Action — giữ shape giống editor bundle
     * (article_meta/publish_box) để không mất publish status/visibility/SEO revision snapshot
     * khi cutover từ controller trực tiếp sang Action.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function buildEditorBundle(SeoArticle $article, string $title, ?string $slugInput, array $input): array
    {
        $articleMeta = [
            'title' => $title !== '' ? $title : (string) $article->title,
            'slug' => $slugInput !== null && $slugInput !== ''
                ? $slugInput
                : (string) ($article->slug ?? ''),
        ];
        if (array_key_exists('seo_meta_description', $input)) {
            $articleMeta['seo_meta_description'] = (string) $input['seo_meta_description'];
        }
        if (array_key_exists('focus_keyword', $input)) {
            $articleMeta['focus_keyword'] = (string) $input['focus_keyword'];
        }

        $publishBox = [
            'status' => (string) ($input['status'] ?? $article->status ?? 'draft'),
            'post_type' => (string) ($input['post_type'] ?? $article->type ?? 'article'),
        ];
        foreach (['visibility', 'publish_day', 'publish_month', 'publish_year', 'publish_hour', 'publish_minute'] as $field) {
            if (array_key_exists($field, $input)) {
                $publishBox[$field] = (string) $input[$field];
            }
        }

        return [
            'article_meta' => $articleMeta,
            'publish_box' => $publishBox,
        ];
    }
}
