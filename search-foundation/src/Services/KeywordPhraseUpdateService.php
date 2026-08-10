<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Illuminate\Support\Facades\DB;

final class KeywordPhraseUpdateService
{
    public function __construct(
        private readonly ArticleWordPressSyncFlagService $syncFlags,
        private readonly KeywordMetaRepository $metaRepository,
        private readonly WordPressArticleContentService $wpContent,
    ) {}

    public function propagate(Keyword $keyword, string $previousPhrase): void
    {
        $previousPhrase = trim($previousPhrase);
        $newPhrase = trim((string) $keyword->phrase);
        if ($previousPhrase === '' || $newPhrase === '' || $previousPhrase === $newPhrase) {
            return;
        }

        DB::connection('omi_seo_ai')->transaction(function () use ($keyword, $previousPhrase, $newPhrase): void {
            if (! Keyword::isNormalType($keyword->type)) {
                return;
            }

            $this->updateMainKeywordMeta($keyword, $newPhrase);
            $this->replaceInternalLinkAnchors($keyword, $previousPhrase, $newPhrase);
        });
    }

    private function updateMainKeywordMeta(Keyword $keyword, string $newPhrase): void
    {
        $articleIds = collect();

        $mainArticleId = $this->metaRepository->getMainArticleId((int) $keyword->id);
        if ($mainArticleId !== null) {
            $articleIds->push($mainArticleId);
        }

        foreach ($articleIds as $articleId) {
            $article = SeoArticle::query()->find((int) $articleId);
            $article?->articleMetas()->updateOrCreate(
                ['meta_key' => 'seo_focus_keyword'],
                ['meta_value' => $newPhrase],
            );
        }
    }

    private function replaceInternalLinkAnchors(
        Keyword $keyword,
        string $previousPhrase,
        string $newPhrase,
    ): void {
        $maps = $keyword->linkMaps()
            ->whereNotNull('source_article_id')
            ->with(['sourceArticle', 'targetArticle'])
            ->get();

        foreach ($maps->groupBy('source_article_id') as $articleId => $articleMaps) {
            $article = SeoArticle::query()->find((int) $articleId);
            if (! $article instanceof SeoArticle) {
                continue;
            }

            $urls = $articleMaps
                ->map(function (SeoLinkMap $map): ?string {
                    $external = trim((string) ($map->target_external_url ?? ''));
                    if ($external !== '') {
                        return $external;
                    }

                    $target = $map->targetArticle;
                    if (! $target instanceof SeoArticle) {
                        return null;
                    }

                    $url = trim($this->wpContent->resolvePermalink($target));

                    return $url !== '' ? $url : null;
                })
                ->filter()
                ->values()
                ->all();

            $body = trim((string) ($article->body ?? ''));
            if ($body === '') {
                $body = trim((string) $article->articleMetas()
                    ->where('meta_key', 'wp_post_content')
                    ->value('meta_value'));
            }

            $updatedBody = $this->replaceAnchorsInHtml($body, $urls, $previousPhrase, $newPhrase);
            if ($updatedBody !== $body && $updatedBody !== '') {
                $article->forceFill(['body' => $updatedBody])->save();
                try {
                    $writer = app(\Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentWriter::class);
                    $writer->invalidateForLegacyBodyWrite($article, 'keyword_phrase_update');
                    if ($article->isDirty('editor_document_status')) {
                        $article->save();
                    }
                } catch (\Throwable) {
                    // best-effort
                }
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => 'wp_post_content'],
                    ['meta_value' => $updatedBody],
                );
                $this->syncFlags->markLocalEditPending($article);
            }
        }
    }

    /**
     * @param  list<string>  $urls
     */
    public function replaceAnchorsInHtml(
        string $html,
        array $urls,
        string $previousPhrase,
        string $newPhrase,
    ): string {
        if ($html === '' || $urls === []) {
            return $html;
        }

        $normalizedUrls = array_map(
            static fn (string $url): string => mb_strtolower(rtrim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'), '/')),
            $urls,
        );

        $result = preg_replace_callback(
            '/(<a\b[^>]*\bhref\s*=\s*(["\'])([^"\']+)\2[^>]*>)([\s\S]*?)(<\/a>)/iu',
            static function (array $matches) use ($normalizedUrls, $previousPhrase, $newPhrase): string {
                $href = mb_strtolower(rtrim(html_entity_decode(trim((string) $matches[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), '/'));
                $anchor = trim(html_entity_decode(strip_tags((string) $matches[4]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                if (! in_array($href, $normalizedUrls, true) || mb_strtolower($anchor) !== mb_strtolower($previousPhrase)) {
                    return (string) $matches[0];
                }

                return (string) $matches[1]
                    .htmlspecialchars($newPhrase, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    .(string) $matches[5];
            },
            $html,
        );

        return is_string($result) ? $result : $html;
    }
}
