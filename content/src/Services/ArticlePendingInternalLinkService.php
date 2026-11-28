<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\SeoPendingInternalLink;

/**
 * Legacy Pending Internal Link helpers.
 *
 * Create / deferred-process workflows are retired (Idea Manager owns phrase intake).
 * Historical SeoPendingInternalLink rows are left untouched (no delete/backfill).
 * resolve* methods are intentional no-ops so WP sync / focus attach cannot mutate articles.
 * Intentional no-ops: resolveForKeyword / resolveForMainArticle return 0.
 */
final class ArticlePendingInternalLinkService
{
    /**
     * @deprecated Pending Internal Link create path retired. Use IdeaManagerKeywordIntakeService::addPhrases.
     *
     * @return array{success: bool, message: string}
     */
    public function assignFromEditor(SeoArticle $sourceArticle, string $anchorPhrase, ?int $projectId = null): array
    {
        unset($sourceArticle, $anchorPhrase, $projectId);

        return [
            'success' => false,
            'message' => 'Pending Internal Link create path is retired. Use Idea Manager phrase intake.',
        ];
    }

    /**
     * Deferred resolve retired — returns 0 without mutating articles or pending rows.
     */
    public function resolveForKeyword(int $keywordId): int
    {
        unset($keywordId);

        return 0;
    }

    /**
     * Deferred resolve retired — returns 0 without mutating articles or pending rows.
     */
    public function resolveForMainArticle(SeoArticle $article): int
    {
        unset($article);

        return 0;
    }

    /**
     * Pure HTML helper retained for historical placeholder cleanup / tests.
     */
    public function replacePlaceholderInHtml(string $html, string $placeholderHash, string $targetUrl): string
    {
        $hash = trim($placeholderHash, '#');
        $targetUrl = trim($targetUrl);
        if ($hash === '' || $targetUrl === '') {
            return $html;
        }

        $variants = array_unique([$hash, strtoupper($hash), strtolower($hash)]);
        $next = $html;

        foreach ($variants as $variant) {
            $next = str_replace(
                [
                    'href="#'.$variant.'"',
                    "href='#".$variant."'",
                ],
                'href="'.str_replace('"', '&quot;', $targetUrl).'"',
                $next,
            );
        }

        return $next;
    }

    /**
     * Model retained for admin cleaners; no create API.
     *
     * @return class-string<SeoPendingInternalLink>
     */
    public static function pendingModelClass(): string
    {
        return SeoPendingInternalLink::class;
    }
}
