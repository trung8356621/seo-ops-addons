<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Support\KeywordPhraseMatcher;
use App\Models\Site;

final class DomainLinkListEditorService
{
    public function __construct(
        private readonly EffectiveDomainLinkResolver $effectiveLinks,
    ) {}

    /**
     * Effective Domain Link List (custom + product_cat + main domain) kèm usage count.
     *
     * @return list<array{
     *     text: string,
     *     href: string,
     *     target_url: string,
     *     keyword_id: int|null,
     *     article_count: int,
     *     can_insert: bool,
     *     source: string,
     *     priority: int,
     * }>
     */
    public function forSite(Site|int|null $site): array
    {
        if ($site === null) {
            return [];
        }

        $site = $site instanceof Site ? $site : Site::query()->find((int) $site);
        if ($site === null) {
            return [];
        }

        $siteId = (int) $site->getKey();
        $links = $this->effectiveLinks->forSite($site);
        if ($links === []) {
            return [];
        }

        $phrases = [];
        foreach ($links as $row) {
            $phrase = trim((string) ($row['keyword'] ?? ''));
            if ($phrase !== '') {
                $phrases[] = $phrase;
            }
        }

        $keywordsByPhrase = Keyword::query()
            ->forSite($siteId)
            ->where('type', Keyword::TYPE_NORMAL)
            ->whereIn('phrase', $phrases)
            ->withCount([
                'linkMaps as linked_articles_count' => static fn ($mapQuery) => $mapQuery
                    ->whereHas(
                        'sourceArticle',
                        static fn ($articleQuery) => $articleQuery->where('site_id', $siteId),
                    ),
            ])
            ->get(['id', 'phrase'])
            ->keyBy(fn (Keyword $keyword): string => mb_strtolower(trim((string) $keyword->phrase)));

        $items = [];

        foreach ($links as $row) {
            $phrase = trim((string) ($row['keyword'] ?? ''));
            $href = trim((string) ($row['link'] ?? ''));
            if ($phrase === '' || $href === '') {
                continue;
            }

            /** @var Keyword|null $keyword */
            $keyword = $keywordsByPhrase->get(mb_strtolower($phrase));

            $items[] = [
                'text' => $phrase,
                'href' => $href,
                'target_url' => $href,
                'keyword_id' => $keyword !== null ? (int) $keyword->id : null,
                'article_count' => (int) ($keyword->linked_articles_count ?? 0),
                'can_insert' => true,
                'source' => (string) ($row['source'] ?? EffectiveDomainLinkResolver::SOURCE_CUSTOM),
                'priority' => (int) ($row['priority'] ?? 1),
            ];
        }

        return $items;
    }

    /**
     * Effective catalog đã lọc: chỉ keyword/anchor có trong nội dung bài (giống Internal suggestions).
     *
     * @return list<array{
     *     text: string,
     *     href: string,
     *     target_url: string,
     *     keyword_id: int|null,
     *     article_count: int,
     *     can_insert: bool,
     *     source: string,
     *     priority: int,
     * }>
     */
    public function forArticle(SeoArticle $article, ?string $contentHtml = null): array
    {
        $items = $this->forSite($article->site);
        if ($items === []) {
            return [];
        }

        $plainText = $this->plainTextFromHtml($contentHtml ?? (string) ($article->body ?? ''));
        if ($plainText === '') {
            return [];
        }

        $filtered = [];
        foreach ($items as $item) {
            if ($this->textContainsPhrase($plainText, (string) $item['text'])) {
                $filtered[] = $item;
            }
        }

        return $filtered;
    }

    private function plainTextFromHtml(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    private function textContainsPhrase(string $text, string $phrase): bool
    {
        return KeywordPhraseMatcher::contains($text, $phrase);
    }
}
