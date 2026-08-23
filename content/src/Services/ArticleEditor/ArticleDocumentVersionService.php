<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleEditor;

use Omnichannel\Addons\Content\Models\SeoArticle;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical document_version authority for articles.body mutations.
 * seo_article_revisions is snapshot history only — not reused as version counter.
 */
final class ArticleDocumentVersionService
{
    private const LAST_BUMP_CACHE_TTL_SECONDS = 3600;

    public function current(SeoArticle $article): int
    {
        if (! $this->columnExists()) {
            return 1;
        }

        return max(1, (int) ($article->document_version ?? 1));
    }

    /**
     * Increment when body is dirty. Safe to call from model observer or writers.
     */
    public function bumpIfBodyChanging(SeoArticle $article): void
    {
        if (! $this->columnExists()) {
            return;
        }

        if (
            class_exists(\Omnichannel\Addons\SiteSync\Support\SiteSyncImportContext::class)
            && \Omnichannel\Addons\SiteSync\Support\SiteSyncImportContext::isActive()
            && (! $article->isDirty('body') || $article->body === null)
        ) {
            return;
        }

        if (! $article->isDirty('body') && ! $article->isDirty('editor_document')) {
            return;
        }

        $before = max(1, (int) ($article->getOriginal('document_version') ?? $article->document_version ?? 1));
        // Already advanced in this write cycle (writer set version) — do not double-bump.
        if ($article->isDirty('document_version')
            && (int) ($article->document_version ?? 0) > $before) {
            if (config('app.debug')) {
                RuntimeLogger::info('seo.editor.version_debug', [
                    'event' => 'bump_skipped_already_advanced',
                    'article_id' => (int) ($article->getKey() ?? 0),
                    'before_version' => $before,
                    'after_version' => (int) $article->document_version,
                    'dirty_fields' => array_values(array_keys($article->getDirty())),
                    'bump_source' => 'observer_skip',
                ]);
            }

            return;
        }

        $article->document_version = $before + 1;

        $this->rememberBump($article, $before, (int) $article->document_version, 'observer');

        if (config('app.debug')) {
            RuntimeLogger::info('seo.editor.version_debug', [
                'event' => 'bump_if_body_changing',
                'article_id' => (int) ($article->getKey() ?? 0),
                'before_version' => $before,
                'after_version' => (int) $article->document_version,
                'dirty_fields' => array_values(array_keys($article->getDirty())),
                'bump_source' => 'observer',
            ]);
        }
    }

    public function ensureDefaultOnCreate(SeoArticle $article): void
    {
        if (! $this->columnExists()) {
            return;
        }

        if ($article->document_version === null || (int) $article->document_version < 1) {
            $article->document_version = 1;
        }
    }

    /**
     * Assert expected version matches current (optimistic concurrency).
     *
     * @param  array<string, mixed>  $context
     *
     * @throws ArticleEditorSessionException
     */
    public function assertExpected(SeoArticle $article, int|string|null $expected, array $context = []): void
    {
        if ($expected === null || $expected === '') {
            return;
        }

        $expectedVersion = (int) $expected;
        $actual = $this->current($article);

        if ($expectedVersion !== $actual) {
            $articleId = (int) $article->getKey();
            $requestId = $this->resolveRequestId($context);
            RuntimeLogger::warning('seo.editor.document_version_conflict', [
                'article_id' => $articleId,
                'base_revision' => $expectedVersion,
                'server_revision' => $actual,
                'expected' => $expectedVersion,
                'actual' => $actual,
                'editor_session_id' => $context['editor_session_id'] ?? null,
                'operation' => $context['operation'] ?? 'assert_expected',
                'request_id' => $requestId,
                'timestamp' => now()->toIso8601String(),
                'last_bump' => $this->lastBump($articleId),
            ]);

            throw ArticleEditorSessionException::make(
                \Omnichannel\Addons\Content\Support\ArticleEditorSessionErrorCode::DOCUMENT_VERSION_CONFLICT,
                'Document version conflict; refusing overwrite.',
                [
                    'expected_document_version' => $expectedVersion,
                    'actual_document_version' => $actual,
                    'editor_session_id' => $context['editor_session_id'] ?? null,
                    'operation' => $context['operation'] ?? 'assert_expected',
                    'request_id' => $requestId,
                ],
                409,
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lastBump(int $articleId): ?array
    {
        if ($articleId <= 0) {
            return null;
        }

        try {
            $payload = Cache::get($this->lastBumpCacheKey($articleId));

            return is_array($payload) ? $payload : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function rememberBump(SeoArticle $article, int $before, int $after, string $source): void
    {
        $articleId = (int) ($article->getKey() ?? 0);
        if ($articleId <= 0) {
            return;
        }

        $payload = [
            'article_id' => $articleId,
            'before_version' => $before,
            'after_version' => $after,
            'bump_source' => $source,
            'dirty_fields' => array_values(array_keys($article->getDirty())),
            'at' => now()->toIso8601String(),
        ];

        try {
            Cache::put($this->lastBumpCacheKey($articleId), $payload, self::LAST_BUMP_CACHE_TTL_SECONDS);
        } catch (\Throwable) {
            // Diagnosis aid only.
        }
    }

    private function lastBumpCacheKey(int $articleId): string
    {
        return 'seo.editor.document_version.last.'.$articleId;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveRequestId(array $context): ?string
    {
        $fromContext = trim((string) ($context['request_id'] ?? ''));
        if ($fromContext !== '') {
            return $fromContext;
        }

        try {
            $header = trim((string) (request()?->header('X-Editor-Save-Request-Id') ?? ''));
            if ($header !== '') {
                return $header;
            }
        } catch (\Throwable) {
            // ignore
        }

        try {
            if (class_exists(\App\Core\Operations\CorrelationId::class)) {
                return \App\Core\Operations\CorrelationId::get();
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function columnExists(): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }

        try {
            $exists = Schema::connection('omi_seo_ai')->hasColumn('articles', 'document_version');
        } catch (\Throwable) {
            $exists = false;
        }

        return $exists;
    }
}
