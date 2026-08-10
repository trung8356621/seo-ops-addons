<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Support\ArticleLanguageCode;
use PHPUnit\Framework\TestCase;

final class ArticleLanguageCodeTest extends TestCase
{
    /**
     * @dataProvider canonicalProvider
     */
    public function test_normalize_maps_known_values_to_codes(string $input, string $expected): void
    {
        self::assertSame($expected, ArticleLanguageCode::normalize($input));
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
            'label_vi' => ['Tiếng Việt', 'vi'],
            'english_name_vi' => ['Vietnamese', 'vi'],
            'en' => ['en', 'en'],
            'EN' => ['EN', 'en'],
            'English' => ['English', 'en'],
            'en-US' => ['en-US', 'en'],
        ];
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
    }

    public function test_unknown_short_code_passes_through_lowercased(): void
    {
        self::assertSame('ja', ArticleLanguageCode::normalize('JA'));
        self::assertSame('fr', ArticleLanguageCode::normalize('fr'));
    }
}
