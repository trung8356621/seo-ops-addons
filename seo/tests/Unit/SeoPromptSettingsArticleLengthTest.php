<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use PHPUnit\Framework\TestCase;

final class SeoPromptSettingsArticleLengthTest extends TestCase
{
    public function test_parse_article_length_target_extracts_first_integer(): void
    {
        $this->assertSame(2000, SeoPromptSettingsService::parseArticleLengthTarget('2000'));
        $this->assertSame(1500, SeoPromptSettingsService::parseArticleLengthTarget('khoảng 1500 từ'));
    }

    public function test_parse_article_length_target_falls_back_when_missing_digits(): void
    {
        $this->assertSame(1000, SeoPromptSettingsService::parseArticleLengthTarget('không rõ', 1000));
    }

    public function test_with_defaults_prompt_variables_expose_numeric_word_targets(): void
    {
        $service = SeoPromptSettingsService::withDefaults();
        $product = $service->promptVariables('product');
        $article = $service->promptVariables('article');

        $this->assertSame('1000', $product['article_length']);
        $this->assertSame('2000', $article['article_length']);
        $this->assertTrue(ctype_digit($product['article_length']));
        $this->assertTrue(ctype_digit($article['article_length']));
    }
}
