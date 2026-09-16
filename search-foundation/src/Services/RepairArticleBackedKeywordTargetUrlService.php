<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\KeywordMeta;
use Omnichannel\Addons\WordPress\Services\WordPressInternalLinkTargetPolicy;
use Illuminate\Support\Facades\DB;

/**
 * Removes duplicate site.{siteId}.target_url from article-backed keywords so
 * WordPress permalinks remain the sole URL SoT for focus articles.
 */
final class RepairArticleBackedKeywordTargetUrlService
{
    public function __construct(
        private readonly KeywordMetaRepository $metaRepository,
        private readonly WordPressInternalLinkTargetPolicy $linkTargetPolicy,
    ) {}

    /**
     * @return array{
     *     dry_run: bool,
     *     site_id: int|null,
     *     examined: int,
     *     cleared: int,
     *     skipped_manual: int,
     *     skipped_no_focus: int,
     *     skipped_cross_site: int,
     *     cleared_rows: list<array{keyword_id: int, site_id: int, article_id: int, old_url: string, reason: string}>
     * }
     */
    public function repair(bool $dryRun = true, ?int $siteId = null): array
    {
        $examined = 0;
        $cleared = 0;
        $skippedManual = 0;
        $skippedNoFocus = 0;
        $skippedCrossSite = 0;
        $clearedRows = [];

        $query = KeywordMeta::query()
            ->where('meta_key', 'like', 'site.%.target_url')
            ->whereNotNull('meta_value')
            ->where('meta_value', '!=', '')
            ->orderBy('id');

        if ($siteId !== null && $siteId > 0) {
            $query->where('meta_key', KeywordMetaKey::siteTargetUrl($siteId));
        }

        $query->chunkById(200, function ($rows) use (
            $dryRun,
            $siteId,
            &$examined,
            &$cleared,
            &$skippedManual,
            &$skippedNoFocus,
            &$skippedCrossSite,
            &$clearedRows,
        ): void {
            foreach ($rows as $row) {
                if (! $row instanceof KeywordMeta) {
                    continue;
                }

                $examined++;
                $keywordId = (int) $row->keyword_id;
                $metaSiteId = KeywordMetaKey::siteIdFromKey((string) $row->meta_key);
                if ($metaSiteId === null || $metaSiteId <= 0 || $keywordId <= 0) {
                    continue;
                }

                if ($siteId !== null && $siteId > 0 && $metaSiteId !== $siteId) {
                    $skippedCrossSite++;
                    continue;
                }

                $focusArticleId = $this->metaRepository->getMainArticleIdForSite($keywordId, $metaSiteId);
                if ($focusArticleId === null || $focusArticleId <= 0) {
                    $skippedNoFocus++;
                    $skippedManual++;

                    continue;
                }

                $articleSiteId = DB::connection('omi_seo_ai')
                    ->table('articles')
                    ->where('id', $focusArticleId)
                    ->value('site_id');

                if (! is_numeric($articleSiteId) || (int) $articleSiteId !== $metaSiteId) {
                    $skippedCrossSite++;
                    continue;
                }

                $oldUrl = trim((string) ($row->meta_value ?? ''));
                $article = SeoArticle::query()
                    ->with(['wordpressLink', 'articleMetas' => static fn ($q) => $q->where('meta_key', 'wp_permalink')])
                    ->find($focusArticleId);

                $reason = 'article_backed_duplicate_target_url';
                if ($article instanceof SeoArticle && ! $this->linkTargetPolicy->isEligibleLinkTarget($article)) {
                    $reason = 'focus_article_not_wp_synced';
                }

                if (! $dryRun) {
                    $this->metaRepository->setSiteTargetUrl($keywordId, $metaSiteId, null);
                }

                $cleared++;
                $clearedRows[] = [
                    'keyword_id' => $keywordId,
                    'site_id' => $metaSiteId,
                    'article_id' => $focusArticleId,
                    'old_url' => $oldUrl,
                    'reason' => $reason,
                ];
            }
        });

        return [
            'dry_run' => $dryRun,
            'site_id' => $siteId !== null && $siteId > 0 ? $siteId : null,
            'examined' => $examined,
            'cleared' => $cleared,
            'skipped_manual' => $skippedManual,
            'skipped_no_focus' => $skippedNoFocus,
            'skipped_cross_site' => $skippedCrossSite,
            'cleared_rows' => $clearedRows,
        ];
    }
}
