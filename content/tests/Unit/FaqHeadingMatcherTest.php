<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Seo\Support\FaqHeadingMatcher;
use PHPUnit\Framework\TestCase;

final class FaqHeadingMatcherTest extends TestCase
{
    public function test_matches_numbered_bold_and_colon_faq_headings(): void
    {
        $matcher = new FaqHeadingMatcher(['faq', 'frequently asked questions', 'câu hỏi thường gặp']);

        $this->assertTrue($matcher->matches('## FAQ'));
        $this->assertTrue($matcher->matches('### FAQ:'));
        $this->assertTrue($matcher->matches('## 5. FAQ'));
        $this->assertTrue($matcher->matches('### 5) FAQ'));
        $this->assertTrue($matcher->matches('#### **FAQ**'));
        $this->assertTrue($matcher->matches('## 5 - FAQ'));
        $this->assertTrue($matcher->matches('## Frequently Asked Questions'));
        $this->assertTrue($matcher->matches('### 6. Frequently Asked Questions:'));
        $this->assertTrue($matcher->matches('## **Frequently Asked Questions**'));
        $this->assertTrue($matcher->matches('## Câu hỏi thường gặp'));
        $this->assertTrue($matcher->matches('### 5. Câu hỏi thường gặp:'));
        $this->assertTrue($matcher->matches('## **CÂU HỎI THƯỜNG GẶP**'));
        $this->assertTrue($matcher->matches('## FAQ Section'));
        $this->assertTrue($matcher->matches('## Frequently Asked Questions (FAQ)'));
    }

    public function test_does_not_match_faq_letters_inside_unrelated_token(): void
    {
        $matcher = new FaqHeadingMatcher(['faq']);

        $this->assertFalse($matcher->matches('## Traffic analysis'));
        $this->assertFalse($matcher->matches('## Prefabrication guide'));
    }

    public function test_ignores_empty_and_duplicate_keywords(): void
    {
        $matcher = new FaqHeadingMatcher(['faq', '', 'FAQ', '  faq  ', 'hỏi đáp']);

        $this->assertSame(['faq', 'hỏi đáp'], $matcher->keywords());
        $this->assertTrue($matcher->matches('## Hỏi đáp'));
    }

    public function test_respects_custom_keyword_lines(): void
    {
        $matcher = new FaqHeadingMatcher(['tư vấn thực tế']);

        $this->assertTrue($matcher->matches('## Chuyên gia tư vấn thực tế'));
        $this->assertFalse($matcher->matches('## FAQ'));
    }
}
