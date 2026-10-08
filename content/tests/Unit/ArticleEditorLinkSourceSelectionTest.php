<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleEditorLinksPayloadService;
use ReflectionClass;
use Tests\TestCase;

final class ArticleEditorLinkSourceSelectionTest extends TestCase
{
    public function test_four_flag_combinations_select_one_engine_per_category(): void
    {
        $cases = [
            [false, false, true, true],
            [true, false, false, true],
            [false, true, true, false],
            [true, true, false, false],
        ];

        foreach ($cases as [$internalV2, $wikiV2, $legacyInternal, $legacyExternal]) {
            config([
                'semantic.internal_link_v2' => $internalV2,
                'semantic.wiki_suggestions' => $wikiV2,
            ]);
            $channels = ArticleEditorLinksPayloadService::legacyChannels();
            self::assertSame($legacyInternal, $channels['internal']);
            self::assertSame($legacyExternal, $channels['external']);
        }
    }

    public function test_v2_replaces_legacy_suggestions_instead_of_appending(): void
    {
        $source = (string) file_get_contents((string) (new ReflectionClass(ArticleEditorLinksPayloadService::class))->getFileName());
        $merge = $this->method($source, 'mergeSemanticSuggestions');
        $withSuggestions = $this->method($source, 'withSuggestions');

        self::assertStringNotContainsString('array_merge(', $merge);
        self::assertStringContainsString("['suggestions']", $merge);
        self::assertStringContainsString('legacyChannels()', $withSuggestions);
        self::assertStringContainsString("\$channels['internal']", $withSuggestions);
        self::assertStringContainsString("\$channels['external']", $withSuggestions);
        self::assertStringNotContainsString("array_merge(\n                    \$internal['suggestions']", $source);
    }

    public function test_manual_more_actions_do_not_call_legacy_when_internal_v2_is_on(): void
    {
        $source = (string) file_get_contents((string) (new ReflectionClass(ArticleEditorLinksPayloadService::class))->getFileName());

        foreach (['withFallbackOnly', 'withAdvancedBatch'] as $method) {
            $body = $this->method($source, $method);
            self::assertStringContainsString('semantic.internal_link_v2', $body);
            self::assertLessThan(
                strpos($body, 'suggestFallbackSupplement') ?: strpos($body, 'suggestAdvancedBatch'),
                strpos($body, 'semantic.internal_link_v2'),
            );
        }
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
