<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\SearchFoundation\Support\KeywordPhraseMatcher;
use PHPUnit\Framework\TestCase;

final class KeywordPhraseMatcherTest extends TestCase
{
    public function test_apostrophe_variants_match(): void
    {
        $content = 'May TÃºi Äeo ChÃ©o KIDâ€™S CLUB cho thÆ°Æ¡ng hiá»‡u thá»i trang.';

        $this->assertTrue(KeywordPhraseMatcher::contains($content, "tÃºi Ä‘eo chÃ©o kid's club"));
        $this->assertTrue(KeywordPhraseMatcher::contains($content, 'tÃºi Ä‘eo chÃ©o kids club'));
        $this->assertSame('tÃºi Ä‘eo chÃ©o kids club', KeywordPhraseMatcher::normalize("TÃºi Äeo ChÃ©o KID'S CLUB"));
    }

    public function test_keyword_missing_in_meta_is_case_insensitive(): void
    {
        $meta = 'MÃ´ táº£ sáº£n pháº©m tÃºi Ä‘eo chÃ©o kids club chÃ­nh hÃ£ng.';

        $this->assertTrue(KeywordPhraseMatcher::contains($meta, 'TÃšI ÄEO CHÃ‰O KIDS CLUB'));
        $this->assertTrue(KeywordPhraseMatcher::contains($meta, mb_strtolower('TÃšI ÄEO CHÃ‰O KIDS CLUB', 'UTF-8')));
    }

    public function test_seo_analyzer_lowercases_keyword_before_meta_check(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/resources/js/utils/seoAnalyzer.js',
        );

        self::assertStringContainsString('toLocaleLowerCase()', $source);
        self::assertStringContainsString('keywordForMatch', $source);
        self::assertStringContainsString('containsKeywordPhrase(metaDescription, keywordForMatch)', $source);
    }

    public function test_count_occurrences_ignores_punctuation(): void
    {
        $content = <<<'TEXT'
        TÃºi Ä‘eo chÃ©o KID'S CLUB lÃ  sáº£n pháº©m hot.
        Nhiá»u shop chá»n tÃºi Ä‘eo chÃ©o kids club.
        TEXT;

        $this->assertSame(2, KeywordPhraseMatcher::countOccurrences($content, "tÃºi Ä‘eo chÃ©o kid's club"));
    }
}
