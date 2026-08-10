<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Seo\Support\CtaKeywordBlacklistFilter;

final class CtaKeywordBlacklistDebugService
{
    public function __construct(
        private readonly OutlineSkipListMatcher $skipListMatcher,
    ) {}

    /**
     * Dò bảng `keywords` theo blacklist trên form (từ khóa còn sót / exclude mới thêm).
     *
     * @param  list<string>  $blacklist
     * @return array{
     *     scanned_keywords: int,
     *     matched_keywords: list<array{id: int, phrase: string, type: string, matched_rules: list<string>}>,
     * }
     */
    public function scan(?int $siteId, array $blacklist): array
    {
        $blacklist = app(SeoKeywordSettingsService::class)->normalizeBlacklist($blacklist);

        $keywordQuery = Keyword::query()->orderBy('phrase');

        if ($siteId !== null && $siteId > 0) {
            $keywordQuery->forSite($siteId);
        }

        $keywords = $keywordQuery->get(['id', 'phrase', 'type']);
        $matchedKeywords = [];

        foreach ($keywords as $keyword) {
            $phrase = Keyword::decodePhrase((string) $keyword->phrase);
            if ($phrase === '') {
                continue;
            }

            $matchedRules = CtaKeywordBlacklistFilter::matchingBlacklistEntries(
                $phrase,
                $blacklist,
                $this->skipListMatcher,
            );

            if ($matchedRules === []) {
                continue;
            }

            $matchedKeywords[] = [
                'id' => (int) $keyword->id,
                'phrase' => $phrase,
                'type' => (string) $keyword->type,
                'matched_rules' => $matchedRules,
            ];
        }

        usort(
            $matchedKeywords,
            static fn (array $left, array $right): int => strnatcasecmp($left['phrase'], $right['phrase']),
        );

        return [
            'scanned_keywords' => $keywords->count(),
            'matched_keywords' => $matchedKeywords,
        ];
    }
}
