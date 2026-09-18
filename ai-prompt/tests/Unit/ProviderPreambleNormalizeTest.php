<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\InvalidOutput;
use Omnichannel\Addons\AiPrompt\PromptHooks\Output\PromptHookRuntimeOutputPipeline;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ProviderPreambleNormalizeTest extends TestCase
{
    public function test_strips_short_chat_preamble_when_body_remains(): void
    {
        $pipeline = new PromptHookRuntimeOutputPipeline;
        $method = new ReflectionMethod($pipeline, 'normalizeProviderPreamble');
        $method->setAccessible(true);

        $input = "Sure! Here's the article you requested.\n\n".str_repeat('<p>Nội dung bài viết SEO dài. </p>', 20);
        $out = $method->invoke($pipeline, $input);

        self::assertStringNotContainsString('Sure!', $out);
        self::assertStringContainsString('<p>Nội dung bài viết SEO dài.', $out);
    }

    public function test_rejects_preamble_only_payload(): void
    {
        $pipeline = new PromptHookRuntimeOutputPipeline;
        $method = new ReflectionMethod($pipeline, 'normalizeProviderPreamble');
        $method->setAccessible(true);

        $this->expectException(InvalidOutput::class);
        $method->invoke($pipeline, 'Sure, I have written the article for you.');
    }

    public function test_leaves_normal_article_unchanged(): void
    {
        $pipeline = new PromptHookRuntimeOutputPipeline;
        $method = new ReflectionMethod($pipeline, 'normalizeProviderPreamble');
        $method->setAccessible(true);

        $input = '<h2>Giới thiệu</h2><p>'.str_repeat('Nội dung. ', 40).'</p>';
        self::assertSame($input, $method->invoke($pipeline, $input));
    }
}
