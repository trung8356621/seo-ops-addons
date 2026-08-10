<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Extension;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Publishing\Application\Publishing\ArticlePublishPayload;
use Omnichannel\Addons\Publishing\Application\Publishing\ContentPublisher;
use Omnichannel\Addons\Publishing\Application\Publishing\PublishResult;
use Omnichannel\Addons\WordPress\Services\SideEffect\ManualWordPressContext;
use Omnichannel\Addons\WordPress\Services\WordPressSlugFixRequiredException;
use Omnichannel\Addons\WordPress\Services\WordPressWriteReadinessGuard;
use Omnichannel\Addons\WordPress\Services\WordPressArticleSyncService;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Built-in WordPress publisher — Application chỉ resolve qua PublisherResolver / ContentPublisherRegistry.
 *
 * - No wp_post_id + queue actor → deliveryRequested (automation creates/syncs).
 * - Existing wp_post_id → update path (never treat as noop solely because wp_post_id is set).
 * - Manual actor → publishForArticle (create if needed, then sync content).
 */
final class WordPressPublisher implements ContentPublisher
{
    public function __construct(
        private readonly WordPressArticleSyncService $syncService,
    ) {}

    public function publish(ArticlePublishPayload $payload): PublishResult
    {
        $article = SeoArticle::query()->find($payload->articleId);
        if (! $article instanceof SeoArticle) {
            return new PublishResult(false, null, 'Article not found.');
        }

        $existingFromRef = $this->findByExternalReference($payload->siteId, $payload->externalReference);
        if ($existingFromRef !== null && $existingFromRef > 0 && (int) ($article->wordpressLink?->wp_post_id ?? 0) <= 0) {
            $this->stampArticleWpPost($payload->articleId, $existingFromRef);
            $article = $article->fresh() ?? $article;
        }

        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? $payload->wpPostId ?? 0);

        try {
            app(WordPressWriteReadinessGuard::class)->assertCanWriteToWordPress($article, 'wordpress_publisher.publish');
        } catch (WordPressSlugFixRequiredException) {
            return new PublishResult(
                success: false,
                wpPostId: null,
                message: WordPressSlugFixRequiredException::MESSAGE,
                externalReference: $payload->externalReference,
            );
        }

        // Queue/system: emit delivery so automation pushes latest local content
        // (create OR update). Do not short-circuit on stale wp_post_id.
        if ($payload->actorUserId === null || $payload->actorUserId <= 0) {
            $this->recordAttempt($payload, 'requested', null, $wpPostId > 0 ? $wpPostId : null);

            return new PublishResult(
                success: true,
                wpPostId: $wpPostId > 0 ? $wpPostId : null,
                message: $wpPostId > 0
                    ? 'Publish update delivery requested via queue event.'
                    : 'Publish delivery requested via queue event.',
                alreadyPublished: false,
                externalReference: $payload->externalReference,
                deliveryRequested: true,
            );
        }

        try {
            $sideEffect = new ManualWordPressContext(
                userId: $payload->actorUserId,
                requestId: $payload->attemptRef,
                articleId: $payload->articleId,
                siteId: $payload->siteId,
                reason: 'content_project.publish:'.$payload->attemptRef,
                correlationId: $payload->idempotencyKey ?? $payload->attemptRef,
            );

            $result = $this->syncService->publishForArticle($article, $sideEffect);
            $resolvedWp = (int) ($article->fresh()?->wordpressLink?->wp_post_id ?? 0);
            if ($resolvedWp <= 0 && is_array($result)) {
                $resolvedWp = (int) ($result['wp_post_id'] ?? 0);
            }

            if ($resolvedWp <= 0 && is_array($result) && ! ($result['success'] ?? false)) {
                $message = (string) ($result['message'] ?? 'WordPress publish failed.');
                $this->recordAttempt($payload, 'failed', $message);

                return new PublishResult(false, null, $message, externalReference: $payload->externalReference);
            }

            if ($resolvedWp <= 0) {
                $this->recordAttempt($payload, 'failed', 'publish returned no wp_post_id');

                return new PublishResult(false, null, 'WordPress publish did not return wp_post_id.');
            }

            $this->recordAttempt($payload, 'published', null, $resolvedWp);

            $permalink = '';
            if (is_array($result)) {
                $permalink = trim((string) ($result['permalink'] ?? ''));
            }
            if ($permalink === '') {
                $permalink = $this->resolveStoredPermalink($payload->articleId);
            }
            if ($permalink !== '') {
                $this->persistPermalink($payload->articleId, $permalink);
            }

            return new PublishResult(
                success: true,
                wpPostId: $resolvedWp,
                message: $wpPostId > 0
                    ? 'Updated existing WordPress post.'
                    : 'Published to WordPress.',
                externalReference: $payload->externalReference,
                permalink: $permalink !== '' ? $permalink : null,
            );
        } catch (Throwable $e) {
            $reconciled = $this->findByExternalReference($payload->siteId, $payload->externalReference);
            if ($reconciled !== null && $reconciled > 0) {
                $this->stampArticleWpPost($payload->articleId, $reconciled);
                $this->recordAttempt($payload, 'published', null, $reconciled);

                return new PublishResult(
                    true,
                    $reconciled,
                    'Reconciled after error/timeout.',
                    true,
                    $payload->externalReference,
                );
            }

            $fresh = (int) (SeoArticle::query()->find($payload->articleId)?->wordpressLink?->wp_post_id ?? 0);
            if ($fresh > 0) {
                $this->recordAttempt($payload, 'published', null, $fresh);

                return new PublishResult(true, $fresh, 'Reconciled article wp_post_id after error.', true, $payload->externalReference);
            }

            $this->recordAttempt($payload, 'failed', $e->getMessage());
            RuntimeLogger::warning('content_publisher.wordpress_failed', [
                'article_id' => $payload->articleId,
                'attempt_ref' => $payload->attemptRef,
                'message' => $e->getMessage(),
            ]);

            return new PublishResult(false, null, $e->getMessage());
        }
    }

    public function findByExternalReference(int $siteId, string $externalReference): ?int
    {
        unset($siteId);

        if ($externalReference === '' || ! Schema::connection('omi_seo_ai')->hasTable('seo_content_project_publish_attempts')) {
            return null;
        }

        $row = DB::connection('omi_seo_ai')->table('seo_content_project_publish_attempts')
            ->where('external_reference', $externalReference)
            ->whereNotNull('wp_post_id')
            ->where('wp_post_id', '>', 0)
            ->orderByDesc('id')
            ->first();

        return $row !== null ? (int) $row->wp_post_id : null;
    }

    private function stampArticleWpPost(int $articleId, int $wpPostId): void
    {
        SeoArticle::query()->whereKey($articleId)->update([
            'wp_post_id' => $wpPostId,
        ]);
    }

    private function resolveStoredPermalink(int $articleId): string
    {
        $article = SeoArticle::query()->with([
            'articleMetas' => static fn ($q) => $q->where('meta_key', 'wp_permalink'),
        ])->find($articleId);

        if (! $article instanceof SeoArticle) {
            return '';
        }

        return trim((string) ($article->articleMetas->firstWhere('meta_key', 'wp_permalink')?->meta_value ?? ''));
    }

    private function persistPermalink(int $articleId, string $permalink): void
    {
        $permalink = trim($permalink);
        if ($articleId <= 0 || $permalink === '') {
            return;
        }

        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle) {
            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'wp_permalink'],
            ['meta_value' => $permalink],
        );
    }

    private function recordAttempt(
        ArticlePublishPayload $payload,
        string $status,
        ?string $error,
        ?int $wpPostId = null,
    ): void {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_content_project_publish_attempts')) {
            return;
        }

        try {
            DB::connection('omi_seo_ai')->table('seo_content_project_publish_attempts')->updateOrInsert(
                ['attempt_ref' => $payload->attemptRef],
                [
                    'project_id' => $payload->projectId,
                    'task_id' => $payload->taskId,
                    'article_id' => $payload->articleId,
                    'external_reference' => $payload->externalReference,
                    'wp_post_id' => $wpPostId,
                    'status' => $status,
                    'idempotency_key' => $payload->idempotencyKey,
                    'last_error' => $error,
                    'requested_at' => now(),
                    'completed_at' => $status === 'published' ? now() : null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        } catch (Throwable) {
            // never break publish path
        }
    }
}
