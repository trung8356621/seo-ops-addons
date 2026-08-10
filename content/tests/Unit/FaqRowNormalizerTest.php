<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Seo\Support\FaqRowNormalizer;
use PHPUnit\Framework\TestCase;

final class FaqRowNormalizerTest extends TestCase
{
    public function test_normalizes_standard_keys(): void
    {
        $rows = FaqRowNormalizer::normalizeList([
            ['question' => 'Q1', 'answer' => 'A1', 'more' => 'M1'],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('Q1', $rows[0]['question']);
        $this->assertSame('A1', $rows[0]['answer']);
        $this->assertSame('M1', $rows[0]['more']);
    }

    public function test_normalizes_alternate_keys_and_more_only_answer(): void
    {
        $rows = FaqRowNormalizer::normalizeList([
            ['title' => 'Q2', 'body' => 'A2'],
            ['q' => 'Q3', 'more' => 'Only more text'],
        ]);

        $this->assertCount(2, $rows);
        $this->assertSame('A2', $rows[0]['answer']);
        $this->assertSame('Only more text', $rows[1]['answer']);
    }

    public function test_skips_rows_without_answer(): void
    {
        $rows = FaqRowNormalizer::normalizeList([
            ['question' => 'No answer'],
        ]);

        $this->assertSame([], $rows);
    }
}
