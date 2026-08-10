<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\PromptLanguageVariableService;
use PHPUnit\Framework\TestCase;

final class PromptLanguageVariableServiceTest extends TestCase
{
    public function test_it_uses_article_language_slug_when_present(): void
    {
        $service = new PromptLanguageVariableService;

        self::assertSame('Vietnamese', $service->resolve(null, 'vi'));
        self::assertSame('English', $service->resolve(null, 'en'));
    }

    public function test_merge_into_skips_existing_language(): void
    {
        $service = new PromptLanguageVariableService;

        $merged = $service->mergeInto(['language' => 'French']);

        self::assertSame('French', $merged['language']);
    }
}
