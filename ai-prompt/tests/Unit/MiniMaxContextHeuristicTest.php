<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\ModelContextCapabilityResolver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class MiniMaxContextHeuristicTest extends TestCase
{
    public function test_minimax_avoids_conservative_4096_window(): void
    {
        $method = new ReflectionMethod(ModelContextCapabilityResolver::class, 'heuristicContext');
        $method->setAccessible(true);
        $guess = $method->invoke(new ModelContextCapabilityResolver, 'minimaxai/minimax-m3.1-flash');

        self::assertSame(128_000, $guess);
        self::assertGreaterThan(
            ModelContextCapabilityResolver::CONSERVATIVE_CONTEXT_WINDOW,
            (int) $guess,
        );
    }

    public function test_nemotron_avoids_conservative_4096_window(): void
    {
        $method = new ReflectionMethod(ModelContextCapabilityResolver::class, 'heuristicContext');
        $method->setAccessible(true);
        $guess = $method->invoke(new ModelContextCapabilityResolver, 'nvidia/nemotron-3.5-lightning-30b-a3b');

        self::assertSame(128_000, $guess);
        self::assertGreaterThan(
            ModelContextCapabilityResolver::CONSERVATIVE_CONTEXT_WINDOW,
            (int) $guess,
        );
    }
}
