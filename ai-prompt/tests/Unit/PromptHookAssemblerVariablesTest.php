<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookPromptAssembler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PromptHookAssemblerVariablesTest extends TestCase
{
    public function test_hook_input_block_does_not_include_article_id(): void
    {
        /** @var PromptHookPromptAssembler $assembler */
        $assembler = (new ReflectionClass(PromptHookPromptAssembler::class))
            ->newInstanceWithoutConstructor();

        $variables = $assembler->buildVariables(
            ['keyword' => 'kw', 'old_title' => null],
            ['max_length' => 65],
        );

        self::assertSame('kw', $variables['keyword']);
        self::assertSame('null', $variables['old_title']);
        self::assertSame('65', $variables['max_length']);
        self::assertStringContainsString('[HOOK_INPUT]', $variables['input']);
        self::assertStringContainsString('keyword: kw', $variables['input']);
        self::assertStringContainsString('old_title: null', $variables['input']);
        self::assertSame($variables['input'], $variables['hook_input']);
        self::assertArrayNotHasKey('article_id', $variables);
        self::assertStringNotContainsString('article_id', $variables['input']);
    }
}
