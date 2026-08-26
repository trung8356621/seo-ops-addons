<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Support\PromptAiCallErrorNormalizer;
use PHPUnit\Framework\TestCase;

final class PromptAiCallErrorNormalizerTest extends TestCase
{
    public function test_normalizes_falsey_error_values(): void
    {
        self::assertNull(PromptAiCallErrorNormalizer::display(null));
        self::assertNull(PromptAiCallErrorNormalizer::display(false));
        self::assertNull(PromptAiCallErrorNormalizer::display(''));
        self::assertSame('AI call failed.', PromptAiCallErrorNormalizer::display('false'));
        self::assertSame('AI call failed.', PromptAiCallErrorNormalizer::display(true));
        self::assertSame('boom', PromptAiCallErrorNormalizer::display('boom'));
        self::assertSame('no routes', PromptAiCallErrorNormalizer::display(['message' => 'no routes']));
    }
}
