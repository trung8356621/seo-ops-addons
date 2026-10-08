<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleEditorLinksPayloadService;
use Omnichannel\Addons\Content\Services\ExternalWikiSuggestionService;
use ReflectionClass;
use Tests\TestCase;

final class ArticleEditorLinkSourceSelectionTest extends TestCase
{
    public function test_internal_channel_stays_off_when_the_removed_flag_is_set(): void
    {
        foreach ([false, true] as $wikiV2) {
            config([
                'semantic.internal_link_v2' => false,
                'semantic.wiki_suggestions' => $wikiV2,
            ]);
            $channels = ArticleEditorLinksPayloadService::legacyChannels();
            self::assertFalse($channels['internal']);
            self::assertSame(! $wikiV2, $channels['external']);
        }
    }

    public function test_v2_replaces_legacy_suggestions_instead_of_appending(): void
    {
        $source = (string) file_get_contents((string) (new ReflectionClass(ArticleEditorLinksPayloadService::class))->getFileName());
        $merge = $this->method($source, 'mergeSemanticSuggestions');
        $withSuggestions = $this->method($source, 'withSuggestions');

        self::assertStringNotContainsString('array_merge(', $merge);
        self::assertStringContainsString("['suggestions']", $merge);
        self::assertStringContainsString('InternalLinkV2Suggester', $merge);
        self::assertStringContainsString('false,', $withSuggestions);
        self::assertStringContainsString("scope !== 'external'", $merge);
        self::assertStringContainsString("scope !== 'internal'", $merge);
        self::assertStringNotContainsString('suggestBundle', $merge);
        self::assertStringNotContainsString('semantic.internal_link_v2', $source);
        self::assertStringNotContainsString('suggestFallbackSupplement', $source);
        self::assertStringNotContainsString('suggestAdvancedBatch', $source);
    }

    public function test_manual_more_actions_call_v2_only(): void
    {
        $source = (string) file_get_contents((string) (new ReflectionClass(ArticleEditorLinksPayloadService::class))->getFileName());

        $fallback = $this->method($source, 'withFallbackOnly');
        self::assertStringContainsString('InternalLinkV2Suggester', $fallback);
        self::assertStringNotContainsString('suggestFallbackSupplement', $fallback);

        $advanced = $this->method($source, 'withAdvancedBatch');
        self::assertStringContainsString('withFallbackOnly', $advanced);
        self::assertStringNotContainsString('suggestAdvancedBatch', $advanced);
    }

    public function test_suggestion_engines_keep_internal_on_v2(): void
    {
        config([
            'semantic.internal_link_v2' => false,
            'semantic.wiki_suggestions' => false,
        ]);
        self::assertSame(
            ['internal' => 'semantic_v2', 'external' => 'legacy'],
            ArticleEditorLinksPayloadService::suggestionEngines(),
        );

        config([
            'semantic.wiki_suggestions' => true,
        ]);
        self::assertSame(
            ['internal' => 'semantic_v2', 'external' => 'wiki_v2'],
            ArticleEditorLinksPayloadService::suggestionEngines(),
        );
    }

    public function test_scope_recomputes_only_the_requested_category(): void
    {
        config([
            'semantic.internal_link_v2' => false,
            'semantic.wiki_suggestions' => true,
        ]);
        self::assertSame(
            ['internal' => false, 'external' => false],
            ArticleEditorLinksPayloadService::channelsForScope('internal'),
        );
        self::assertSame(
            ['internal' => false, 'external' => false],
            ArticleEditorLinksPayloadService::channelsForScope('external'),
        );

        config([
            'semantic.wiki_suggestions' => false,
        ]);
        self::assertSame(
            ['internal' => false, 'external' => false],
            ArticleEditorLinksPayloadService::channelsForScope('internal'),
        );
        self::assertSame(
            ['internal' => false, 'external' => true],
            ArticleEditorLinksPayloadService::channelsForScope('external'),
        );
        self::assertSame('both', ArticleEditorLinksPayloadService::normalizeScope('other'));
    }

    public function test_semantic_errors_do_not_select_the_legacy_collector(): void
    {
        $v2 = (string) file_get_contents((string) (new ReflectionClass(\Omnichannel\Addons\Content\Services\InternalLinkV2Suggester::class))->getFileName());
        self::assertStringContainsString("'status' => 'unavailable', 'suggestions' => []", $v2);
        self::assertStringNotContainsString('suggestBundle', $v2);
        self::assertStringNotContainsString('suggestFallbackSupplement', $v2);

        $wiki = (string) file_get_contents((string) (new ReflectionClass(ExternalWikiSuggestionService::class))->getFileName());
        self::assertStringContainsString("'status' => 'unavailable', 'suggestions' => []", $wiki);
        self::assertStringNotContainsString('suggestBundle', $wiki);
    }

    public function test_fragment_suggestions_are_dropped_and_real_urls_stay(): void
    {
        $service = (new ReflectionClass(ArticleEditorLinksPayloadService::class))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass(ArticleEditorLinksPayloadService::class))->getMethod('withoutFragmentSuggestions');
        $kept = $method->invoke($service, [
            ['text' => 'hash', 'href' => '#'],
            ['text' => 'fragment', 'href' => '#section'],
            ['text' => 'empty', 'href' => ''],
            ['text' => 'real', 'href' => 'https://example.com/bags'],
        ]);

        self::assertSame([['text' => 'real', 'href' => 'https://example.com/bags']], $kept);
    }

    public function test_wiki_urls_reject_social_hosts(): void
    {
        self::assertTrue(ExternalWikiSuggestionService::isWikipediaArticleUrl('https://vi.wikipedia.org/wiki/USB'));
        self::assertTrue(ExternalWikiSuggestionService::isWikipediaArticleUrl('https://en.wikipedia.org/wiki/USB'));
        self::assertFalse(ExternalWikiSuggestionService::isWikipediaArticleUrl('https://zalo.me/123'));
        self::assertFalse(ExternalWikiSuggestionService::isWikipediaArticleUrl('https://facebook.com/page'));
        self::assertFalse(ExternalWikiSuggestionService::isWikipediaArticleUrl('https://en.wikipedia.org/wiki/Special:Search'));
    }

    private function method(string $source, string $name): string
    {
        $start = strpos($source, 'function '.$name);
        self::assertNotFalse($start);
        $next = strpos($source, "\n    function ", (int) $start + 10);
        if ($next === false) {
            $next = strpos($source, "\n    public ", (int) $start + 10);
        }
        if ($next === false) {
            $next = strpos($source, "\n    private ", (int) $start + 10);
        }

        return substr($source, (int) $start, ($next === false ? strlen($source) : $next) - (int) $start);
    }
}
