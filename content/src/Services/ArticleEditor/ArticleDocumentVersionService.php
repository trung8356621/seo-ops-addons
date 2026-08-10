<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleEditor;

use Omnichannel\Addons\Content\Models\SeoArticle;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical document_version authority for articles.body mutations.
 * seo_article_revisions is snapshot history only — not reused as version counter.
 */
final class ArticleDocumentVersionService
{
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
     * @throws ArticleEditorSessionException
     */
    public function assertExpected(SeoArticle $article, int|string|null $expected): void
    {
        if ($expected === null || $expected === '') {
            return;
        }

        $expectedVersion = (int) $expected;
        $actual = $this->current($article);

        if ($expectedVersion !== $actual) {
            RuntimeLogger::warning('seo.editor.document_version_conflict', [
                'article_id' => (int) $article->getKey(),
                'expected' => $expectedVersion,
                'actual' => $actual,
            ]);

            throw ArticleEditorSessionException::make(
                \Omnichannel\Addons\Content\Support\ArticleEditorSessionErrorCode::DOCUMENT_VERSION_CONFLICT,
                'Document version conflict; refusing overwrite.',
                [
                    'expected_document_version' => $expectedVersion,
                    'actual_document_version' => $actual,
                ],
                409,
            );
        }
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
