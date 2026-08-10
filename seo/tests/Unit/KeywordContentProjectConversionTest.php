<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\CreateContentProjectFromTopicalMapCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\PreviewContentProjectFromTopicalMapCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordTopicalMapToContentProjectConverter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class KeywordContentProjectConversionTest extends TestCase
{
    public function test_commands_use_public_capability_names(): void
    {
        self::assertSame(
            'keyword_intelligence.preview_content_project',
            (new PreviewContentProjectFromTopicalMapCommand('kww_1', 'tmv_1'))->name(),
        );
        self::assertSame(
            'keyword_intelligence.create_content_project',
            (new CreateContentProjectFromTopicalMapCommand('kww_1', 'tmv_1'))->name(),
        );
    }

    public function test_converter_has_no_gallery_description_path(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(KeywordTopicalMapToContentProjectConverter::class))->getFileName()
        );
        self::assertStringContainsString("unset(\$attributes['gallery_description'])", $src);
        self::assertStringNotContainsString('gallery_description =', $src);
    }

    public function test_converter_requires_approved_map_guard(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(KeywordTopicalMapToContentProjectConverter::class))->getFileName()
        );
        self::assertStringContainsString('keyword.conversion.map_not_approved', $src);
        self::assertStringContainsString('idempotency_key_hash', $src);
    }
}
