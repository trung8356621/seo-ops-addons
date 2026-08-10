<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\InvalidInput;
use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookRequireAnyOf;
use PHPUnit\Framework\TestCase;

final class PromptHookRequireAnyOfTest extends TestCase
{
    public function test_keyword_only_satisfies_outline_group(): void
    {
        PromptHookRequireAnyOf::assertSatisfied(
            ['keyword' => 'nghệ thuật Typography', 'post_title' => null],
            ['require_any_of' => [['post_title', 'keyword']]],
        );
        self::assertTrue(true);
    }

    public function test_title_only_satisfies_outline_group(): void
    {
        PromptHookRequireAnyOf::assertSatisfied(
            ['post_title' => 'SEO guide', 'keyword' => ''],
            ['require_any_of' => [['post_title', 'keyword']]],
        );
        self::assertTrue(true);
    }

    public function test_both_empty_fails(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Missing required hook input [post_title|keyword].');
        PromptHookRequireAnyOf::assertSatisfied(
            ['post_title' => '  ', 'keyword' => null],
            ['require_any_of' => [['post_title', 'keyword']]],
        );
    }

    public function test_no_constraint_is_noop(): void
    {
        PromptHookRequireAnyOf::assertSatisfied(['post_title' => ''], []);
        self::assertTrue(true);
    }
}
