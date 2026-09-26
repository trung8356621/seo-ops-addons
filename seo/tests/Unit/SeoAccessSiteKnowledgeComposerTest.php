<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Services\Access\SeoAccessSiteKnowledgeComposer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SeoAccessSiteKnowledgeComposerTest extends TestCase
{
    public function test_source_prefers_official_and_omits_indexability(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SeoAccessSiteKnowledgeComposer::class))->getFileName()
        );

        self::assertStringContainsString('Official Knowledge Profile', $src);
        self::assertStringContainsString('getRawPayloadForSite', $src);
        self::assertStringContainsString('SiteMcpDraft', $src);
        self::assertStringContainsString('firstNonEmpty', $src);
        self::assertStringNotContainsString('indexability', $src);
        self::assertStringNotContainsString('is_indexable', $src);
        self::assertStringNotContainsString('SiteSeoHealthReader', $src);
    }
}
