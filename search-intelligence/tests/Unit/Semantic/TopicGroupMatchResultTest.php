<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Semantic;

use Omnichannel\Addons\SearchIntelligence\Services\Semantic\TopicGroup\TopicGroupMatchResult;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TopicGroupMatchResultTest extends TestCase
{
    #[Test]
    public function external_refs_are_preserved_and_the_result_is_read_only(): void
    {
        $result = TopicGroupMatchResult::fromApi([
            'scope_ref' => 'site:ext-9',
            'matches' => [
                ['ref' => 'tg:cotton', 'score' => 0.91, 'evidence' => ['lexical_matched' => true]],
                ['ref' => '', 'score' => 1],
            ],
        ]);

        self::assertSame('site:ext-9', $result->scopeRef);
        self::assertSame([['ref' => 'tg:cotton', 'score' => 0.91]], $result->matches);
        self::assertFalse(method_exists($result, 'save'));
    }
}
