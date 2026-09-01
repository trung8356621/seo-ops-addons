<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentWriter;

/**
 * Content lifecycle for Article Editor — distinguishes missing local snapshot
 * from a legitimately empty new article.
 *
 * WP-backed + no body/cache → CONTENT_LOADING (client auto-fetches WP JSON).
 * SYNC_REQUIRED is retired from the happy path (legacy alias only).
 */
final class ArticleEditorContentLifecycle
{
    public const CONTENT_LOADING = 'CONTENT_LOADING';

    public const EDITABLE = 'EDITABLE';

    public const SYNC_REQUIRED = 'SYNC_REQUIRED';

    public const NEW_EMPTY_ARTICLE = 'NEW_EMPTY_ARTICLE';

    public const ERROR = 'ERROR';

    public const REJECT_EMPTY_UNHYDRATED_CODE = 'local_content_sync_required';

    /**
     * Pure resolver from already-computed facts (unit-testable without Eloquent).
     *
     * @param  array{
     *     load_completed?: bool,
     *     error?: bool,
     *     wordpress_linked?: bool,
     *     local_content_present?: bool,
     * }  $facts
     */
    public function resolveFromFacts(array $facts): string
    {
        if (($facts['error'] ?? false) === true) {
            return self::ERROR;
        }

        if (($facts['load_completed'] ?? true) !== true) {
            return self::CONTENT_LOADING;
        }

        $wordpressLinked = ($facts['wordpress_linked'] ?? false) === true;
        $localPresent = ($facts['local_content_present'] ?? false) === true;

        if ($wordpressLinked && ! $localPresent) {
            return self::CONTENT_LOADING;
        }

        if (! $wordpressLinked && ! $localPresent) {
            return self::NEW_EMPTY_ARTICLE;
        }

        return self::EDITABLE;
    }

    public function isWordPressLinked(SeoArticle $article): bool
    {
        $article->loadMissing('wordpressLink');

        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        if ($wpPostId > 0) {
            return true;
        }

        // Observed WP relation without stale zero id — rare but valid SoT.
        $observedPermalink = trim((string) ($article->wordpressLink?->observed_permalink ?? ''));
        if ($observedPermalink !== '') {
            return true;
        }

        $observedStatus = strtolower(trim((string) ($article->wordpressLink?->observed_post_status ?? '')));
        if ($observedStatus !== '' && ! in_array($observedStatus, ['missing', 'unknown', 'none'], true)) {
            return true;
        }

        return false;
    }

    public function hasLocalContentSnapshot(SeoArticle $article, ?string $bootstrapHtml = null): bool
    {
        if ($bootstrapHtml !== null && $this->htmlHasMeaningfulContent($bootstrapHtml)) {
            return true;
        }

        if ($this->htmlHasMeaningfulContent((string) ($article->body ?? ''))) {
            return true;
        }

        $document = $article->editor_document;
        if (is_array($document) && $document !== []) {
            if (app(ArticleEditorDocumentWriter::class)->isUsableBootstrapDocument(
                $document,
                (string) ($article->body ?? ''),
            )) {
                return true;
            }

            if ($this->editorDocumentHasMeaningfulText($document)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *     state: string,
     *     wordpress_linked: bool,
     *     local_content_present: bool,
     *     wp_post_id: int,
     *     observed_permalink: string|null,
     *     allow_fetch_from_wordpress: bool,
     * }
     */
    public function bootstrapPayload(SeoArticle $article, string $bootstrapHtml, bool $allowFetchFromWordPress = true): array
    {
        $article->loadMissing('wordpressLink');
        $wordpressLinked = $this->isWordPressLinked($article);
        $localPresent = $this->hasLocalContentSnapshot($article, $bootstrapHtml);
        $state = $this->resolveFromFacts([
            'load_completed' => true,
            'error' => false,
            'wordpress_linked' => $wordpressLinked,
            'local_content_present' => $localPresent,
        ]);

        $observedPermalink = trim((string) ($article->wordpressLink?->observed_permalink ?? ''));
        if ($observedPermalink === '') {
            $observedPermalink = trim((string) (ArticleMetaMap::for($article)->get('wp_permalink', '') ?? ''));
        }

        return [
            'state' => $state,
            'wordpress_linked' => $wordpressLinked,
            'local_content_present' => $localPresent,
            'wp_post_id' => (int) ($article->wordpressLink?->wp_post_id ?? 0),
            'observed_permalink' => $observedPermalink !== '' ? $observedPermalink : null,
            'allow_fetch_from_wordpress' => $allowFetchFromWordPress && $wordpressLinked && ! $localPresent,
        ];
    }

    /**
     * Empty editor save must not hydrate-overwrite a WP-linked article that never had local content.
     * Intentional clear after hydrate is different: local snapshot already existed (body/cache/document).
     */
    public function shouldRejectEmptyPersist(SeoArticle $article, string $incomingHtml): bool
    {
        if ($this->htmlHasMeaningfulContent($incomingHtml)) {
            return false;
        }

        if (! $this->isWordPressLinked($article)) {
            return false;
        }

        // Still has local snapshot in DB → existing guards handle wipe-of-substantial-content.
        // Reject only when local snapshot is missing (unhydrated).
        return ! $this->hasLocalContentSnapshot($article);
    }

    public function htmlHasMeaningfulContent(string $html): bool
    {
        $trimmed = trim($html);
        if ($trimmed === '') {
            return false;
        }

        $plain = html_entity_decode(strip_tags($trimmed), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = str_replace("\u{00A0}", ' ', $plain);
        $plain = preg_replace('/\s+/u', ' ', $plain) ?? $plain;
        $plain = trim($plain);

        return $plain !== '';
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function editorDocumentHasMeaningfulText(array $document): bool
    {
        $blocks = is_array($document['blocks'] ?? null) ? $document['blocks'] : [];
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? 'text');
            if ($type === 'image') {
                $image = is_array($block['image'] ?? null) ? $block['image'] : [];
                $src = trim((string) ($image['src'] ?? $image['url'] ?? ''));
                if ($src !== '') {
                    return true;
                }

                continue;
            }
            $content = trim((string) ($block['content'] ?? ''));
            if ($this->htmlHasMeaningfulContent($content)) {
                return true;
            }
        }

        return false;
    }
}
