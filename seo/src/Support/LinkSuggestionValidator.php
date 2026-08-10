<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;


use Omnichannel\Addons\SearchFoundation\Support\InternalAnchorKeywordFilter;
/**
 * Phân biệt anchor candidate vs link suggestion hợp lệ.
 */
final class LinkSuggestionValidator
{
    /**
     * @param  array{
     *     text?: mixed,
     *     href?: mixed,
     *     target_url?: mixed,
     *     target_article_id?: mixed,
     *     bucket?: 'internal'|'external'|null
     * }  $suggestion
     * @param  array{
     *     site_domain?: string,
     *     site_id?: int,
     *     current_article_id?: int,
     *     current_urls?: list<string>,
     *     current_slug?: string
     * }  $context
     */
    public static function isValidLinkSuggestion(array $suggestion, array $context = []): bool
    {
        $anchor = trim((string) ($suggestion['text'] ?? ''));
        if ($anchor === '' || ! InternalAnchorKeywordFilter::isUsableAnchorPhrase($anchor)) {
            return false;
        }

        $url = trim((string) ($suggestion['href'] ?? $suggestion['target_url'] ?? ''));
        if ($url === '' || SeoSuggestionUrlNormalizer::isPlaceholder($url)) {
            return false;
        }

        if (! SeoSuggestionUrlNormalizer::isParsableTarget($url, allowRelative: true)) {
            return false;
        }

        $bucket = $suggestion['bucket'] ?? null;
        $siteDomain = SeoLinkMapLinkTypeClassifier::normalizeDomainHost((string) ($context['site_domain'] ?? ''));
        $urlHost = SeoSuggestionUrlNormalizer::host($url);

        if ($bucket === 'internal') {
            $targetArticleId = (int) ($suggestion['target_article_id'] ?? 0);
            if ($targetArticleId <= 0 && ! self::looksInternalForSite($url, $siteDomain)) {
                return false;
            }
        }

        if ($bucket === 'external') {
            if (str_starts_with($url, '/')) {
                return false;
            }
            if ($siteDomain !== '' && $urlHost !== '' && $urlHost === $siteDomain) {
                return false;
            }
        }

        if (self::isSelfLink($url, $suggestion, $context)) {
            return false;
        }

        return true;
    }

    /**
     * AI / ranker chỉ được chọn candidate trong allowlist (ID ổn định).
     *
     * @param  list<int>  $allowedCandidateIds
     */
    public static function isAllowedCandidateId(?int $candidateId, array $allowedCandidateIds): bool
    {
        if ($candidateId === null || $candidateId <= 0) {
            return false;
        }

        return in_array($candidateId, $allowedCandidateIds, true);
    }

    /**
     * Reject URL AI bịa không nằm trong candidate URLs đã validate.
     *
     * @param  list<string>  $allowedUrls
     */
    public static function isAllowedCandidateUrl(string $url, array $allowedUrls): bool
    {
        $normalized = SeoSuggestionUrlNormalizer::normalize($url);
        if ($normalized === '') {
            return false;
        }

        foreach ($allowedUrls as $allowed) {
            if (SeoSuggestionUrlNormalizer::normalize((string) $allowed) === $normalized) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $suggestion
     * @param  array{
     *     current_article_id?: int,
     *     current_urls?: list<string>,
     *     current_slug?: string,
     *     site_domain?: string
     * }  $context
     */
    public static function isSelfLink(string $url, array $suggestion = [], array $context = []): bool
    {
        $currentArticleId = (int) ($context['current_article_id'] ?? 0);
        $targetArticleId = (int) ($suggestion['target_article_id'] ?? 0);
        if ($currentArticleId > 0 && $targetArticleId > 0 && $currentArticleId === $targetArticleId) {
            return true;
        }

        $normalizedUrl = SeoSuggestionUrlNormalizer::normalize($url);
        if ($normalizedUrl === '') {
            return false;
        }

        foreach (($context['current_urls'] ?? []) as $currentUrl) {
            $normalizedCurrent = SeoSuggestionUrlNormalizer::normalize((string) $currentUrl);
            if ($normalizedCurrent !== '' && $normalizedCurrent === $normalizedUrl) {
                return true;
            }
        }

        $currentSlug = trim((string) ($context['current_slug'] ?? ''), '/');
        $siteDomain = SeoLinkMapLinkTypeClassifier::normalizeDomainHost((string) ($context['site_domain'] ?? ''));
        if ($currentSlug === '') {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) && str_starts_with(trim($url), '/')) {
            $path = trim($url);
        }
        if (! is_string($path) || $path === '') {
            return false;
        }

        $slug = basename(rtrim($path, '/'));
        $slug = (string) (preg_replace('/\.html?$/i', '', $slug) ?? $slug);
        if ($slug === '' || strcasecmp($slug, $currentSlug) !== 0) {
            return false;
        }

        $urlHost = SeoSuggestionUrlNormalizer::host($url);
        if ($urlHost === '' && str_starts_with(trim($url), '/')) {
            return true;
        }

        return $siteDomain !== '' && $urlHost === $siteDomain;
    }

    private static function looksInternalForSite(string $url, string $siteDomain): bool
    {
        if (str_starts_with(trim($url), '/')) {
            return true;
        }

        $host = SeoSuggestionUrlNormalizer::host($url);

        return $siteDomain !== '' && $host !== '' && $host === $siteDomain;
    }
}
