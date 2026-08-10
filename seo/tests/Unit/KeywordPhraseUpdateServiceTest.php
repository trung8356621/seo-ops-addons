<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Services\KeywordPhraseUpdateService;
use Tests\TestCase;

final class KeywordPhraseUpdateServiceTest extends TestCase
{
    public function test_it_replaces_only_matching_internal_link_anchor(): void
    {
        $html = '<p><a href="https://example.com/balo">balo đẹp</a> và '
            .'<a href="https://example.com/khac">balo đẹp</a></p>';

        $updated = app(KeywordPhraseUpdateService::class)->replaceAnchorsInHtml(
            $html,
            ['https://example.com/balo'],
            'balo đẹp',
            'balo thời trang',
        );

        self::assertStringContainsString(
            '<a href="https://example.com/balo">balo thời trang</a>',
            $updated,
        );
        self::assertStringContainsString(
            '<a href="https://example.com/khac">balo đẹp</a>',
            $updated,
        );
    }
}
