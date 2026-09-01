<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Omnichannel\Addons\SiteSync\Services\Profile\WordPressSiteProfileReader;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class WordPressSiteProfileReaderTest extends TestCase
{
    public function test_reader_normalizes_profile_fields_and_falls_back_to_schema_org(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(WordPressSiteProfileReader::class))->getFileName(),
        );

        self::assertStringContainsString('site_name', $source);
        self::assertStringContainsString('short_description', $source);
        self::assertStringContainsString("profile['site_name']", $source);
        self::assertStringContainsString("schema_org", $source);
        self::assertStringContainsString('/omi-seo-ai/v1/sync/v2/profile', $source);
        self::assertStringContainsString('fetchProfile', $source);
    }
}
