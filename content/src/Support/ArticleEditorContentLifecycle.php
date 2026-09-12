<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use Omnichannel\Addons\Content\Models\SeoArticle;

/**
 * Content lifecycle for Article Editor.
 *
 * Contract: `articles.body` is the only SoT for "has local content".
 * `editor_document`, WP cache, and bootstrapHtml must not change that semantic.
 *
 * WP-backed + empty body → CONTENT_LOADING (client auto-hydrates WP → persist body).
 * SYNC_REQUIRED is a legacy alias only (normalized to CONTENT_LOADING).
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

    /**
     * Local content = meaningful `articles.body` only.
     *
     * `$bootstrapHtml` is accepted for call-site BC but never participates
     * in the presence decision (must not change local_content_present).
     */
    public function hasLocalContentSnapshot(SeoArticle $article, ?string $bootstrapHtml = null): bool
    {
        unset($bootstrapHtml);

        return $this->htmlHasMeaningfulContent((string) ($article->body ?? ''));
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
    public function bootstrapPayload(SeoArticle $article, string $bootstrapHtml = '', bool $allowFetchFromWordPress = true): array
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
     * Empty editor save must not wipe a WP-linked article that never had local body.
     * Intentional clear after hydrate is different: body already had meaningful content.
     */
    public function shouldRejectEmptyPersist(SeoArticle $article, string $incomingHtml): bool
    {
        if ($this->htmlHasMeaningfulContent($incomingHtml)) {
            return false;
        }

        if (! $this->isWordPressLinked($article)) {
            return false;
        }

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

        if ($plain !== '') {
            return true;
        }

        // Media-only markup still counts as local content (body SoT).
        return preg_match('/<(img|video|audio|iframe|figure)\b/i', $trimmed) === 1;
    }
}
