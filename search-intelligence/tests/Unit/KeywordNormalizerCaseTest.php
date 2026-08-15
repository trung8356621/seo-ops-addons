<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordCanonicalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordClusterKey;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use PHPUnit\Framework\TestCase;

final class KeywordNormalizerCaseTest extends TestCase
{
    public function test_case_variants_share_normalized_and_cluster_key(): void
    {
        $normalizer = new KeywordNormalizer();
        $cluster = new KeywordClusterKey();
        $variants = [
            'May túi vải canvas',
            'MAY TÚI VẢI CANVAS',
            'may túi vải canvas',
            '  TÚI   VẢI Canvas  ',
        ];
        $normalized = [];
        $keys = [];
        foreach ($variants as $raw) {
            $norm = $normalizer->normalize($raw);
            $normalized[] = $norm['normalized_text'];
            $keys[] = $cluster->make($norm['normalized_text'], $norm['folded_text']);
            self::assertSame($raw === '  TÚI   VẢI Canvas  ' ? 'TÚI   VẢI Canvas' : $raw, $norm['raw_text']);
        }

        self::assertSame('may túi vải canvas', $normalized[0]);
        self::assertSame($normalized[0], $normalized[1]);
        self::assertSame($normalized[0], $normalized[2]);
        self::assertSame('túi vải canvas', $normalized[3]);
        self::assertSame($keys[0], $keys[1]);
        self::assertSame($keys[0], $keys[2]);
        self::assertNotSame($keys[0], $keys[3]);
        self::assertSame('tui vai canvas', $normalizer->normalize('túi vải canvas')['folded_text']);
    }

    public function test_near_duplicate_ignores_capitalization(): void
    {
        $normalizer = new KeywordNormalizer();
        $canon = new KeywordCanonicalizer();
        $a = $normalizer->normalize('May túi vải');
        $b = $normalizer->normalize('may túi vải');
        self::assertTrue($canon->isNearDuplicate($a['folded_text'], $b['folded_text']));
        self::assertSame('May túi vải', $canon->pickDisplay([$a, $b]));
    }

    public function test_search_normalized_contains_uppercase_source(): void
    {
        $normalizer = new KeywordNormalizer();
        $haystack = $normalizer->normalize('TÚI VẢI CANVAS QUÀ TẶNG')['normalized_text'];
        $needle = $normalizer->normalize('túi vải canvas')['normalized_text'];
        self::assertStringContainsString($needle, $haystack);
    }
}
