<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Illuminate\Support\Carbon;

final class ArticleEditorReadinessService
{
    public const META_KEY = 'article_editor_readiness';

    public function queueAfterWorkflowRun(SeoArticle $article, int $runId): ArticleEditorReadinessResult
    {
        $article->refresh();
        $this->syncWpPostContentFromBody($article);
        app(ArticleWordPressSyncFlagService::class)->markLocalEditPending($article);

        $expectedHash = $this->bodyHash($article);

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_KEY],
            [
                'meta_value' => json_encode([
                    'status' => 'pending',
                    'run_id' => $runId,
                    'expected_body_sha256' => $expectedHash,
                    'queued_at' => now()->toIso8601String(),
                ], JSON_UNESCAPED_UNICODE),
            ],
        );

        return $this->evaluate($article->fresh() ?? $article);
    }

    public function isReady(int $articleId): bool
    {
        if ($articleId <= 0) {
            return true;
        }

        $article = SeoArticle::query()->find($articleId);

        return $article instanceof SeoArticle
            ? $this->evaluate($article)->isReady
            : false;
    }

    public function evaluate(SeoArticle $article): ArticleEditorReadinessResult
    {
        $article->refresh();
        $article->loadMissing('articleMetas');

        // Job AI crash / worker chết có thể để media.status=processing mãi → khóa editor.
        app(ArticleEditorMediaAiService::class)->reconcileStaleAiMediaJobs((int) $article->id);

        $payload = $this->readPayload($article);
        $expectedHash = trim((string) ($payload['expected_body_sha256'] ?? ''));
        $processingMedia = $this->countProcessingMedia((int) $article->id);
        $currentHash = $this->bodyHash($article);
        $bodySynced = $expectedHash === '' || hash_equals($expectedHash, $currentHash);

        if (! $bodySynced && $expectedHash !== '' && $processingMedia === 0) {
            $queuedAt = trim((string) ($payload['queued_at'] ?? ''));
            if ($queuedAt !== '') {
                try {
                    if (Carbon::parse($queuedAt)->lte(now()->subMinutes(10))) {
                        $bodySynced = true;
                        $expectedHash = $currentHash;
                        $payload['expected_body_sha256'] = $currentHash;
                    }
                } catch (\Throwable) {
                    // Ignore invalid timestamp.
                }
            }
        }

        $reasons = [];
        if ($processingMedia > 0) {
            $reasons[] = 'processing_media:'.$processingMedia;
        }
        if (! $bodySynced) {
            $reasons[] = 'body_not_synced';
        }

        $isReady = $reasons === [];

        if ($payload !== []) {
            $payload['status'] = $isReady ? 'ready' : 'pending';
            $payload['pending_reasons'] = $reasons;
            $payload['evaluated_at'] = now()->toIso8601String();
            if ($isReady) {
                $payload['ready_at'] = now()->toIso8601String();
            }

            $article->articleMetas()->updateOrCreate(
                ['meta_key' => self::META_KEY],
                ['meta_value' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
            );
        }

        return new ArticleEditorReadinessResult(
            isReady: $isReady,
            processingMediaCount: $processingMedia,
            bodySynced: $bodySynced,
            reasons: $reasons,
        );
    }

    public function syncWpPostContentFromBody(SeoArticle $article): void
    {
        $body = trim((string) ($article->body ?? ''));
        if ($body === '') {
            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'wp_post_content'],
            ['meta_value' => $body],
        );
    }

    public function bodyHash(SeoArticle $article): string
    {
        return hash('sha256', trim((string) ($article->body ?? '')));
    }

    public function userMessage(ArticleEditorReadinessResult $result): string
    {
        if ($result->isReady) {
            return '';
        }

        if ($result->processingMediaCount > 0 && ! $result->bodySynced) {
            return __('seo-content-ai::filament.projects.article_editor_preparing_media_and_body');
        }

        if ($result->processingMediaCount > 0) {
            return __('seo-content-ai::filament.projects.article_editor_preparing_media', [
                'count' => $result->processingMediaCount,
            ]);
        }

        return __('seo-content-ai::filament.projects.article_editor_preparing_body');
    }

    /**
     * User bỏ qua màn chuẩn bị: fail job AI đang treo + khớp body hash hiện tại.
     */
    public function abandonPreparingGate(SeoArticle $article, string $reason = ''): ArticleEditorReadinessResult
    {
        $reason = trim($reason);
        if ($reason === '') {
            $reason = 'Người dùng mở editor khi job AI vẫn đang treo.';
        }

        app(ArticleEditorMediaAiService::class)->failAllProcessingAiMediaJobs((int) $article->id, $reason);

        $article->refresh();
        $currentHash = $this->bodyHash($article);
        $payload = $this->readPayload($article);
        $payload['status'] = 'ready';
        $payload['expected_body_sha256'] = $currentHash;
        $payload['pending_reasons'] = [];
        $payload['abandoned_at'] = now()->toIso8601String();
        $payload['abandon_reason'] = mb_substr($reason, 0, 500);
        $payload['ready_at'] = now()->toIso8601String();
        $payload['evaluated_at'] = now()->toIso8601String();

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_KEY],
            ['meta_value' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
        );

        return $this->evaluate($article->fresh() ?? $article);
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(SeoArticle $article): array
    {
        $raw = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', self::META_KEY)?->meta_value ?? ''));

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function countProcessingMedia(int $articleId): int
    {
        if ($articleId <= 0) {
            return 0;
        }

        // article_id + status có thể nằm trong seo_media_meta (SeoMediaBuilder route tự động).
        return (int) SeoMedia::query()
            ->where('status', 'processing')
            ->where('article_id', $articleId)
            ->count();
    }
}

final class ArticleEditorReadinessResult
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public readonly bool $isReady,
        public readonly int $processingMediaCount = 0,
        public readonly bool $bodySynced = true,
        public readonly array $reasons = [],
    ) {}
}
