<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\Vocabulary;

use App\Models\Site;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\AiPrompt\Support\Vocabulary\VocabularyMarkdownCodec;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleKeywordDistinctCounter;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSiteKeyword;

/**
 * Assemble Task 2 vocabulary from site/article business data (no provider call).
 */
final class InternalVocabularyAssembler
{
  private const SITE_KEYWORD_LIMIT = 80;

  public function __construct(
      private readonly InternalVocabularyReuseFilter $reuseFilter,
      private readonly VocabularyMarkdownCodec $codec = new VocabularyMarkdownCodec,
  ) {}

  /**
   * @param  array<string, mixed>  $contextExtras
   * @param  array<string, mixed>  $variables
   * @return array<string, list<string>>
   */
  public function assemble(array $contextExtras, array $variables, string $outlineMarkdown = ''): array
  {
      unset($outlineMarkdown);

      try {
          return $this->assembleUnsafe($contextExtras, $variables);
      } catch (\Throwable) {
          // Tests / partial schemas must not break outline+vocabulary execution.
          return [];
      }
  }

  /**
   * @param  array<string, mixed>  $contextExtras
   * @param  array<string, mixed>  $variables
   * @return array<string, list<string>>
   */
  private function assembleUnsafe(array $contextExtras, array $variables): array
  {
      $articleId = (int) ($contextExtras['article_id'] ?? 0);
      $siteId = (int) ($contextExtras['site_id'] ?? 0);

      if ($this->articlesTableReady() && $articleId > 0) {
          $article = SeoArticle::query()->find($articleId);
          if ($article instanceof SeoArticle) {
              $siteId = $siteId > 0 ? $siteId : (int) $article->site_id;
          }
      }

      if ($siteId <= 0 && $articleId <= 0) {
          return [];
      }

      $groups = [];

      if ($this->articlesTableReady() && $articleId > 0) {
          $groups = $this->mergeGroupMaps($groups, $this->groupsFromArticleMeta($articleId));
      }

      $focus = trim((string) ($variables['focus_keyword'] ?? $variables['keyword'] ?? $variables['input'] ?? ''));
      if ($focus !== '') {
          $groups = $this->appendUnique($groups, 'Semantic keywords', [$focus]);
          $groups = $this->appendUnique($groups, 'Salient keywords', [$focus]);
      }

      if ($siteId > 0) {
          $groups = $this->mergeGroupMaps($groups, $this->groupsFromSiteKeywords($siteId));
          $groups = $this->mergeGroupMaps($groups, $this->groupsFromDomainPromptContext($siteId));
      }

      return $this->normalizeGroups($groups);
  }

  /**
   * @return array<string, list<string>>
   */
    private function articlesTableReady(): bool
    {
        return Schema::connection('omi_seo_ai')->hasTable('articles');
    }

    private function groupsFromArticleMeta(int $articleId): array
    {
      $article = SeoArticle::query()->with(['articleMetas'])->find($articleId);
      if (! $article instanceof SeoArticle) {
          return [];
      }

      $raw = null;
      foreach ($article->articleMetas as $meta) {
          if ((string) ($meta->meta_key ?? '') === ArticleKeywordDistinctCounter::META_KEY) {
              $raw = $meta->meta_value;
              break;
          }
      }

      if ($raw === null) {
          return [];
      }

      $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

      return is_array($decoded) ? $this->groupsFromStoredPayload($decoded) : [];
  }

  /**
   * @param  array<mixed, mixed>  $payload
   * @return array<string, list<string>>
   */
  private function groupsFromStoredPayload(array $payload): array
  {
      $groups = [];

      if (array_is_list($payload)) {
          $phrases = [];
          foreach ($payload as $item) {
              if (is_string($item) || is_numeric($item)) {
                  $phrase = trim((string) $item);
                  if ($phrase !== '') {
                      $phrases[] = $phrase;
                  }
              }
          }
          if ($phrases !== []) {
              $groups['Semantic keywords'] = $phrases;
          }

          return $groups;
      }

      foreach ($payload as $group => $items) {
          if (! is_array($items)) {
              continue;
          }
          $headingName = $this->headingLabel((string) $group);
          foreach ($items as $item) {
              $phrase = is_string($item) || is_numeric($item)
                  ? trim((string) $item)
                  : $this->phraseFromItem($item);
              if ($phrase !== '') {
                  $groups[$headingName][] = $phrase;
              }
          }
      }

      return $groups;
  }

  /**
   * @return array<string, list<string>>
   */
  private function groupsFromSiteKeywords(int $siteId): array
  {
      if (! Schema::connection('omi_seo_ai')->hasTable('seo_site_keywords')) {
          return [];
      }

      $groups = [];
      $rows = SeoSiteKeyword::query()
          ->with(['keyword.metas'])
          ->where('site_id', $siteId)
          ->where('is_seo_keyword', true)
          ->orderByDesc('keyword_score')
          ->limit(self::SITE_KEYWORD_LIMIT)
          ->get();

      foreach ($rows as $row) {
          if (! $row instanceof SeoSiteKeyword) {
              continue;
          }
          $keyword = $row->keyword;
          if (! $keyword instanceof Keyword) {
              continue;
          }
          if ($this->reuseFilter->isExcludedForInternalReuse($keyword, $siteId)) {
              continue;
          }

          $phrase = Keyword::decodePhrase((string) $keyword->phrase);
          if ($phrase === '' || $this->wordCount($phrase) < 2) {
              continue;
          }

          $heading = $this->siteKeywordHeading((string) ($row->phrase_kind ?? ''), $phrase);
          $groups = $this->appendUnique($groups, $heading, [$phrase]);
      }

      return $groups;
  }

  /**
   * @return array<string, list<string>>
   */
  private function groupsFromDomainPromptContext(int $siteId): array
  {
      if (! Schema::connection()->hasTable('sites')) {
          return [];
      }

      $site = Site::query()->find($siteId);
      if ($site === null) {
          return [];
      }

      try {
          $context = app(SiteDomainPromptContextService::class)->getForSite($site);
      } catch (\Throwable) {
          return [];
      }
      $links = is_array($context['links'] ?? null) ? $context['links'] : [];
      $groups = [];

      foreach ($links as $link) {
          if (! is_array($link)) {
              continue;
          }
          $phrase = trim((string) ($link['keyword'] ?? ''));
          if ($phrase === '' || $this->wordCount($phrase) < 2) {
              continue;
          }
          $groups = $this->appendUnique($groups, 'Relevant entities', [$phrase]);
      }

      $identity = trim((string) ($context['company_short_identity'] ?? ''));
      if ($identity !== '' && $this->wordCount($identity) >= 2) {
          $groups = $this->appendUnique($groups, 'Holonymy', [$identity]);
      }

      return $groups;
  }

  /**
   * @param  array<string, list<string>>  $base
   * @param  array<string, list<string>>  $extra
   * @return array<string, list<string>>
   */
  private function mergeGroupMaps(array $base, array $extra): array
  {
      foreach ($extra as $group => $items) {
          $base = $this->appendUnique($base, (string) $group, $items);
      }

      return $base;
  }

  /**
   * @param  array<string, list<string>>  $groups
   * @param  list<string>  $items
   * @return array<string, list<string>>
   */
  private function appendUnique(array $groups, string $heading, array $items): array
  {
      $heading = $this->headingLabel($heading);
      $existing = $groups[$heading] ?? [];
      $groups[$heading] = $this->codec->dedupeItems([...$existing, ...$items]);

      return $groups;
  }

  /**
   * @param  array<string, list<string>>  $groups
   * @return array<string, list<string>>
   */
  private function normalizeGroups(array $groups): array
  {
      $normalized = [];
      foreach ($groups as $heading => $items) {
          $label = $this->headingLabel((string) $heading);
          $normalized[$label] = $this->codec->dedupeItems(is_array($items) ? $items : []);
      }

      return array_filter($normalized, static fn (array $items): bool => $items !== []);
  }

    private function headingLabel(string $raw): string
    {
        $parsed = $this->codec->parse("### {$raw}\n- placeholder");

        return array_key_first($parsed) ?? trim($raw);
    }

  private function siteKeywordHeading(string $phraseKind, string $phrase): string
  {
      return match ($phraseKind) {
          'entity' => 'Semantic entities',
          'brand' => 'Relevant entities',
          'topic' => 'Related topics',
          default => $this->wordCount($phrase) >= 4 ? 'Long-tail keywords' : 'Semantic keywords',
      };
    }

  private function phraseFromItem(mixed $item): string
  {
      if (is_string($item) || is_numeric($item)) {
          return trim((string) $item);
      }

      if (! is_array($item)) {
          return '';
      }

      foreach (['keyword', 'phrase', 'title'] as $key) {
          if (! isset($item[$key])) {
              continue;
          }
          $phrase = trim((string) $item[$key]);
          if ($phrase !== '') {
              return $phrase;
          }
      }

      return '';
  }

  private function wordCount(string $phrase): int
  {
      $parts = preg_split('/\s+/u', trim($phrase)) ?: [];

      return count(array_filter($parts, static fn (string $part): bool => $part !== ''));
  }
}