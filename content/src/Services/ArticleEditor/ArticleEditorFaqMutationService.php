<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleEditor;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleContentFaqService;
use Omnichannel\Addons\Content\Services\ArticleFaqEditorService;
use Omnichannel\Addons\WordPress\Services\ArticleFaqWordPressRestoreService;
use App\Models\User;

/**
 * FAQ domain mutations for Article Editor — persist seo_faqs, return snapshot.
 * Body placeholder inject is explicit apply step (session-aware).
 */
final class ArticleEditorFaqMutationService
{
    public function __construct(
        private readonly ArticleFaqEditorService $faqEditor,
        private readonly ArticleEditorFaqSnapshotService $snapshots,
        private readonly ArticleEditorSessionService $sessions,
        private readonly ArticleContentFaqService $contentFaq,
        private readonly ArticleFaqWordPressRestoreService $wpRestore,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function replaceSnapshot(
        SeoArticle $article,
        User $user,
        array $items,
        ?string $editorSessionId,
        int|string|null $expectedSnapshotVersion = null,
    ): array {
        $this->assertFaqWritable($article, $user, $editorSessionId, $expectedSnapshotVersion);

        $this->faqEditor->saveFromEditor($article, $items);
        $this->snapshots->bumpVersion($article);

        return $this->snapshots->build($article->fresh() ?? $article, $user);
    }

    /**
     * Persist FAQ rows + inject [omi_faq] into body under owning editor session.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{faq_snapshot: array<string, mixed>, editor_html: string}
     */
    public function applyToDocument(
        SeoArticle $article,
        User $user,
        array $items,
        string $editorHtml,
        ?string $editorSessionId,
        int|string|null $expectedDocumentVersion = null,
        int|string|null $expectedSnapshotVersion = null,
    ): array {
        $this->assertFaqWritable($article, $user, $editorSessionId, $expectedSnapshotVersion);

        $this->faqEditor->saveFromEditor($article, $items);
        $this->snapshots->bumpVersion($article);

        $baseHtml = trim($editorHtml);
        if ($baseHtml === '') {
            $baseHtml = trim((string) ($article->body ?? ''));
        }
        if ($baseHtml !== '') {
            $this->wpRestore->persistWordPressSourceSnapshot($article, $baseHtml);
        }

        $newHtml = $this->contentFaq->injectFaqPlaceholderInEditorHtml($baseHtml);
        $this->contentFaq->persistArticleBodyHtml(
            $article,
            $newHtml,
            $user,
            $editorSessionId,
            $expectedDocumentVersion,
        );

        $fresh = $article->fresh() ?? $article;

        return [
            'faq_snapshot' => $this->snapshots->build($fresh, $user),
            'editor_html' => $newHtml,
        ];
    }

    private function assertFaqWritable(
        SeoArticle $article,
        User $user,
        ?string $editorSessionId,
        int|string|null $expectedSnapshotVersion,
    ): void {
        $this->sessions->assertArticleEditable($article);

        if ($expectedSnapshotVersion !== null && $expectedSnapshotVersion !== '') {
            $current = $this->snapshots->currentVersion($article);
            if ((int) $expectedSnapshotVersion !== $current) {
                throw ArticleEditorSessionException::make(
                    'faq_snapshot_conflict',
                    'FAQ snapshot version conflict.',
                    ['expected' => (int) $expectedSnapshotVersion, 'current' => $current],
                    409,
                );
            }
        }

        $active = $this->sessions->findActiveSession($article);
        $sessionId = trim((string) ($editorSessionId ?? ''));
        if ($active !== null) {
            $this->sessions->assertOwningActiveSessionForWrite(
                $article,
                $user,
                $sessionId !== '' ? $sessionId : null,
                null,
            );

            return;
        }

        if ($sessionId !== '') {
            throw ArticleEditorSessionException::make(
                \Omnichannel\Addons\Content\Support\ArticleEditorSessionErrorCode::SESSION_EXPIRED,
                'Editor session expired.',
                [],
                409,
            );
        }
    }
}