<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicListQuery;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class TopicListPaginationPerformanceContractTest extends TestCase
{
    public function test_default_path_paginates_in_database_before_page_metrics(): void
    {
        $source = (string) file_get_contents((string) (new ReflectionClass(TopicListQuery::class))->getFileName());
        $paginate = $this->methodBody($source, 'paginate');

        self::assertStringContainsString('->paginate($perPage', $paginate);
        self::assertStringContainsString('$topicIds = $topics->pluck', $paginate);
        self::assertStringContainsString('countForTopics', $paginate);
        self::assertStringContainsString('mapForTopics($siteId, $topicIds)', $paginate);
        self::assertStringNotContainsString('->get()', $paginate);
        self::assertStringNotContainsString('sortRows', $paginate);
        self::assertStringNotContainsString('array_slice', $paginate);
    }

    public function test_advanced_metric_filter_fallback_is_explicitly_isolated(): void
    {
        $source = (string) file_get_contents((string) (new ReflectionClass(TopicListQuery::class))->getFileName());

        self::assertStringContainsString('paginateWithAdvancedMetricFilters', $source);
        self::assertStringContainsString("if (\$intent !== '' || \$coverage !== '')", $source);
        self::assertStringContainsString('keywordIdSubquery', $source);
        self::assertStringContainsString('eligibleArticleDenominator', $source);
    }

    private function methodBody(string $source, string $method): string
    {
        $pattern = '/function\s+'.preg_quote($method, '/').'\s*\([^)]*\)\s*(?::\s*[^\{]+)?\{/';
        self::assertSame(1, preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE));
        $start = (int) $match[0][1] + strlen($match[0][0]);
        $depth = 1;
        for ($offset = $start, $length = strlen($source); $offset < $length; $offset++) {
            if ($source[$offset] === '{') {
                $depth++;
            } elseif ($source[$offset] === '}' && --$depth === 0) {
                return substr($source, $start, $offset - $start);
            }
        }

        self::fail('Method body not closed: '.$method);
    }
}
