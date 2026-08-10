<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Support\SerpAllintitleQuery;
use Tests\TestCase;

final class SerpAllintitleQueryTest extends TestCase
{
    public function test_build_wraps_keyword_in_allintitle_quotes(): void
    {
        $this->assertSame('allintitle:"seo tool"', SerpAllintitleQuery::build('seo tool'));
    }

    public function test_build_escapes_embedded_quotes(): void
    {
        $this->assertSame('allintitle:"12\" monitor"', SerpAllintitleQuery::build('12" monitor'));
    }

    public function test_build_normalizes_whitespace(): void
    {
        $this->assertSame('allintitle:"a b c"', SerpAllintitleQuery::build("a  \n b   c"));
    }
}
