<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\HideKeywordFromSeoService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordSourceNormalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\VocabularySuggestStagingQuery;
use RuntimeException;

/**
 * Remove Vocabulary Suggest staging candidate from Project Planner pool.
 * Uses seo_hidden soft-hide — does not touch TYPE_NORMAL, article_meta, or source articles.
 */
final class DismissVocabularySuggestCandidateService
{
    public function __construct(
        private readonly HideKeywordFromSeoService $hide,
    ) {}

    /**
     * @return array{keyword_id: int, phrase: string, dismissed: bool}
     */
    public function dismiss(int $keywordId, int $siteId): array
    {
        if ($keywordId <= 0) {
            throw new RuntimeException('invalid_keyword_id');
        }

        $query = VocabularySuggestStagingQuery::forSite($siteId > 0 ? $siteId : 0)
            ->whereKey($keywordId);
        $keyword = $query->first();

        if (! $keyword instanceof Keyword) {
            throw new RuntimeException('suggest_not_found');
        }

        if ((string) ($keyword->type ?? '') !== Keyword::TYPE_SUGGEST) {
            throw new RuntimeException('not_vocabulary_suggest');
        }

        $source = (string) ($keyword->source ?? '');
        if ($source !== KeywordSourceNormalizer::AI_GENERATED) {
            throw new RuntimeException('not_vocabulary_suggest');
        }

        $phrase = Keyword::decodePhrase((string) ($keyword->phrase ?? ''));

        if ($this->hide->isHidden($keywordId)) {
            return [
                'keyword_id' => $keywordId,
                'phrase' => $phrase,
                'dismissed' => true,
            ];
        }

        $result = $this->hide->hide($keywordId, $siteId > 0 ? $siteId : null);

        return [
            'keyword_id' => (int) ($result['keyword_id'] ?? $keywordId),
            'phrase' => (string) ($result['phrase'] ?? $phrase),
            'dismissed' => true,
        ];
    }
}
