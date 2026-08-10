<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordSerpChangeAnalysisService;
use Tests\TestCase;

final class KeywordSerpChangeAnalysisServiceTest extends TestCase
{
    public function test_empty_group_returns_no_changes(): void
    {
        $changes = app(KeywordSerpChangeAnalysisService::class)->buildChanges(null, 'serpapi');

        $this->assertSame([], $changes);
    }
}
