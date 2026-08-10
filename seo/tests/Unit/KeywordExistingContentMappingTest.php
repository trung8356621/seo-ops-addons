<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordExistingContentIndex;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordExistingContentMapper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class KeywordExistingContentMappingTest extends TestCase
{
    public function test_index_and_mapper_exist(): void
    {
        self::assertTrue(class_exists(KeywordExistingContentIndex::class));
        self::assertTrue(class_exists(KeywordExistingContentMapper::class));
    }

    public function test_index_does_not_store_full_body(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(KeywordExistingContentIndex::class))->getFileName(),
        );
        self::assertStringContainsString('search_text', $source);
        self::assertStringNotContainsString('full_body', $source);
        self::assertStringNotContainsString('content_html', $source);
    }
}
