<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ContentLanguageLegacyRepair;
use Omnichannel\Addons\Content\Support\ArticleLanguageCode;
use Omnichannel\Addons\Content\Support\ContentLanguageCodeNormalizer;
use Omnichannel\Addons\Content\Support\ContentLanguageRegistry;
use PHPUnit\Framework\TestCase;

final class ArticleLanguageCodeTest extends TestCase
{
    /**
     * @dataProvider canonicalProvider
     */
    public function test_normalize_maps_known_values_to_codes(string $input, string $expected): void
    {
        self::assertSame($expected, ArticleLanguageCode::normalize($input));
        self::assertSame($expected, ContentLanguageCodeNormalizer::normalize($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function canonicalProvider(): array
    {
        return [
            'vi' => ['vi', 'vi'],
            'VI' => ['VI', 'vi'],
            'vn' => ['vn', 'vi'],
            'VN' => ['VN', 'vi'],
            'vi_VN' => ['vi_VN', 'vi'],
            'vi-VN' => ['vi-VN', 'vi'],
            'whitespace_vi' => [' vi ', 'vi'],
            'label_vi' => ['Tiếng Việt', 'vi'],
            'english_name_vi' => ['Vietnamese', 'vi'],
            'en' => ['en', 'en'],
            'EN' => ['EN', 'en'],
            'English' => ['English', 'en'],
            'en-US' => ['en-US', 'en'],
            'en_US' => ['en_US', 'en'],
            'en-GB' => ['en-GB', 'en'],
            'en_GB' => ['en_GB', 'en'],
            'whitespace_en' => [' en ', 'en'],
        ];
    }

    public function test_null_and_empty_normalize_to_null_via_boundary_normalizer(): void
    {
        self::assertNull(ContentLanguageCodeNormalizer::normalize(null));
        self::assertNull(ContentLanguageCodeNormalizer::normalize(''));
        self::assertNull(ContentLanguageCodeNormalizer::normalize('   '));
        self::assertSame('', ArticleLanguageCode::normalize(null));
        self::assertSame('', ArticleLanguageCode::normalize(''));
    }

    public function test_storage_default_and_labels(): void
    {
        self::assertSame('vi', ArticleLanguageCode::normalizeForStorage(null));
        self::assertSame('vi', ArticleLanguageCode::normalizeForStorage(''));
        self::assertSame('Tiếng Việt', ArticleLanguageCode::label('vi'));
        self::assertSame('English', ArticleLanguageCode::label('en'));
        self::assertSame(['vi' => 'Tiếng Việt', 'en' => 'English'], ArticleLanguageCode::defaultLabels());
    }

    public function test_corrupted_vietnamese_label_is_not_guessed(): void
    {
        self::assertSame('', ArticleLanguageCode::normalize('Ti???ng Vi???t'));
        self::assertSame('vi', ArticleLanguageCode::normalizeForStorage('Ti???ng Vi???t'));
        self::assertNull(ContentLanguageCodeNormalizer::normalize('Ti???ng Vi???t'));
    }

    public function test_unknown_short_code_passes_through_lowercased(): void
    {
        self::assertSame('ja', ArticleLanguageCode::normalize('JA'));
        self::assertSame('fr', ArticleLanguageCode::normalize('fr'));
    }

    public function test_is_canonical_rejects_locales_and_uppercase(): void
    {
        self::assertTrue(ArticleLanguageCode::isCanonicalCode('vi'));
        self::assertTrue(ContentLanguageCodeNormalizer::isCanonical('en'));
        self::assertFalse(ContentLanguageCodeNormalizer::isCanonical('VI'));
        self::assertFalse(ContentLanguageCodeNormalizer::isCanonical('vi_VN'));
        self::assertFalse(ContentLanguageCodeNormalizer::isCanonical('en-US'));
    }

    public function test_registry_supported_codes(): void
    {
        self::assertSame(['vi', 'en'], ContentLanguageRegistry::codes());
        self::assertTrue(ContentLanguageRegistry::isSupported('vi_VN'));
        self::assertFalse(ContentLanguageRegistry::isSupported('ja'));
        self::assertArrayHasKey('vi', ContentLanguageRegistry::selectOptions());
        self::assertArrayHasKey('en', ContentLanguageRegistry::selectOptions());
    }

    public function test_repair_known_aliases_only(): void
    {
        self::assertSame('vi', ContentLanguageCodeNormalizer::repairKnownAlias('vi_VN'));
        self::assertSame('en', ContentLanguageCodeNormalizer::repairKnownAlias('EN'));
        self::assertSame('en', ContentLanguageCodeNormalizer::repairKnownAlias('en-GB'));
        self::assertNull(ContentLanguageCodeNormalizer::repairKnownAlias('not-a-language'));
        self::assertContains('vi_VN', ContentLanguageLegacyRepair::knownStoredVariants('vi'));
        self::assertContains('en_US', ContentLanguageLegacyRepair::knownStoredVariants('en'));
    }
}
