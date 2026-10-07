<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordUiInventoryQuery;

/**
 * Site keyword texts eligible for Group analysis and the unassigned list.
 */
final class KeywordGroupCandidateLoader
{
    /**
     * @param  list<string>|null  $languageVariants
     * @return list<array{keyword_id: int, phrase: string}>
     */
    public function load(int $siteId, ?array $languageVariants = null): array
    {
        if ($siteId <= 0) {
            return [];
        }

        $rows = app(KeywordUiInventoryQuery::class)
            ->apply(Keyword::query(), $siteId, $languageVariants)
            ->orderBy('id')
            ->get(['id', 'phrase']);

        $out = [];
        foreach ($rows as $row) {
            $keywordId = (int) $row->id;
            $phrase = trim((string) $row->phrase);
            if ($keywordId <= 0 || $phrase === '') {
                continue;
            }
            $out[] = [
                'keyword_id' => $keywordId,
                'phrase' => $phrase,
            ];
        }

        return $out;
    }
}
