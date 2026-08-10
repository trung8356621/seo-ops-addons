<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use PHPUnit\Framework\TestCase;

final class KeywordPhraseStorageTest extends TestCase
{
    public function test_normalize_focus_phrase_takes_first_comma_separated_value(): void
    {
        $raw = 'lợi ích của việc cấy ghép răng implant, ưu điểm trồng răng implant, trồng implant';

        $this->assertSame(
            'lợi ích của việc cấy ghép răng implant',
            Keyword::normalizeFocusPhrase($raw),
        );
    }

    public function test_prepare_phrase_for_storage_clamps_to_column_limit(): void
    {
        $longPhrase = str_repeat('a', 300);

        $this->assertSame(Keyword::PHRASE_MAX_LENGTH, mb_strlen(Keyword::preparePhraseForStorage($longPhrase)));
        $this->assertSame(str_repeat('a', Keyword::PHRASE_MAX_LENGTH), Keyword::preparePhraseForStorage($longPhrase));
    }

    public function test_normalize_focus_phrase_splits_on_semicolon(): void
    {
        $this->assertSame(
            'keyword chính',
            Keyword::normalizeFocusPhrase('keyword chính; keyword phụ'),
        );
    }

    public function test_prepare_phrase_for_storage_handles_rank_math_multi_focus_list(): void
    {
        $raw = 'lợi ích của việc cấy ghép răng implant, ưu điểm trồng răng implant, lợi ích trồng răng implant, trồng implant';

        $prepared = Keyword::preparePhraseForStorage($raw);

        $this->assertSame('lợi ích của việc cấy ghép răng implant', $prepared);
        $this->assertLessThanOrEqual(Keyword::PHRASE_MAX_LENGTH, mb_strlen($prepared));
    }
}
