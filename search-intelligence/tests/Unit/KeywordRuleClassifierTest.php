<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordAiCandidateGuard;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordCanonicalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordDictionaryBuilder;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordGenerationContextBuilder;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordIntelligenceDebouncer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordLandscapeBuilder;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordRuleClassifier;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordSourceNormalizer;
use PHPUnit\Framework\TestCase;

final class KeywordRuleClassifierTest extends TestCase
{
    public function test_classifies_vietnamese_taxonomy_examples(): void
    {
        $classifier = new KeywordRuleClassifier();
        $normalizer = new KeywordNormalizer();

        $cases = [
            'xưởng gia công túi vải không dệt' => 'keyword_phrase',
            'xưởng may túi không dệt' => 'keyword_phrase',
            'xưởng may túi không dệt tại TP.HCM' => 'keyword_phrase',
            'mua túi không dệt ở đâu' => 'query',
            'Xưởng Hợp Phát' => 'brand_entity',
            'Xưởng May Hợp Phát – đơn vị sản xuất balo uy tín' => 'descriptive_phrase',
            'Công ty chúng tôi chuyên sản xuất balo theo yêu cầu' => 'sentence',
            'sản xuất balo theo yêu cầu' => 'keyword_phrase',
            'https://example.com' => 'url_domain',
            '--- ///' => 'noise',
            'Nike' => 'brand_entity',
            'balo quà tặng doanh nghiệp' => 'keyword_phrase',
        ];

        foreach ($cases as $raw => $expected) {
            $norm = $normalizer->normalize($raw);
            $result = $classifier->classify($raw, $norm['normalized_text']);
            self::assertSame($expected, $result['phrase_kind'], $raw.' got '.$result['phrase_kind']);
        }

        $long = $classifier->classify(
            'Xưởng May Túi Không Dệt chuyên nghiệp tại Tp. Hồ Chí Minh',
            (new KeywordNormalizer())->normalize('Xưởng May Túi Không Dệt chuyên nghiệp tại Tp. Hồ Chí Minh')['normalized_text'],
        );
        self::assertContains($long['phrase_kind'], ['keyword_phrase', 'descriptive_phrase']);
        self::assertGreaterThanOrEqual(0.60, $long['classification_confidence']);

        $brandLoc = $classifier->classify(
            'Xưởng Hợp Phát tại TP.HCM',
            (new KeywordNormalizer())->normalize('Xưởng Hợp Phát tại TP.HCM')['normalized_text'],
        );
        self::assertContains($brandLoc['phrase_kind'], ['brand_entity', 'keyword_phrase']);
    }

    public function test_descriptive_segments_are_candidates_not_auto_canonical(): void
    {
        $result = (new KeywordRuleClassifier())->classify(
            'Xưởng May Hợp Phát – đơn vị sản xuất balo uy tín',
            'xưởng may hợp phát – đơn vị sản xuất balo uy tín',
        );
        self::assertSame('descriptive_phrase', $result['phrase_kind']);
        self::assertFalse($result['is_seo_keyword']);
        self::assertNotEmpty($result['segments']);
        self::assertSame('Xưởng May Hợp Phát', $result['segments'][0]['text']);
    }

    public function test_source_aware_anchor_does_not_auto_promote(): void
    {
        $classifier = new KeywordRuleClassifier();
        $manual = $classifier->classify('xưởng may balo', 'xưởng may balo', [
            'source_kind' => KeywordSourceNormalizer::MANUAL,
            'occurrence_count' => 1,
        ]);
        $anchorOnce = $classifier->classify('xưởng may balo', 'xưởng may balo', [
            'source_kind' => KeywordSourceNormalizer::ANCHOR_TEXT,
            'occurrence_count' => 1,
        ]);
        $anchorOften = $classifier->classify('xưởng may balo', 'xưởng may balo', [
            'source_kind' => KeywordSourceNormalizer::ANCHOR_TEXT,
            'occurrence_count' => 12,
            'source_post_count' => 8,
        ]);
        $descriptiveAnchor = $classifier->classify(
            'Xưởng May Hợp Phát – đơn vị sản xuất balo uy tín',
            'xưởng may hợp phát – đơn vị sản xuất balo uy tín',
            ['source_kind' => KeywordSourceNormalizer::ANCHOR_TEXT, 'occurrence_count' => 20],
        );

        self::assertTrue($manual['is_seo_keyword']);
        self::assertGreaterThan($anchorOnce['keyword_score'], $manual['keyword_score']);
        self::assertTrue($anchorOften['is_seo_keyword']);
        self::assertFalse($descriptiveAnchor['is_seo_keyword']);
        self::assertSame('descriptive_phrase', $descriptiveAnchor['phrase_kind']);
    }

    public function test_does_not_use_word_count_cutoff_alone(): void
    {
        $longTail = (new KeywordRuleClassifier())->classify(
            'xưởng may túi vải không dệt giá rẻ tại tphcm cho doanh nghiệp',
            'xưởng may túi vải không dệt giá rẻ tại tphcm cho doanh nghiệp',
        );
        self::assertNotSame('sentence', $longTail['phrase_kind']);
        self::assertContains($longTail['phrase_kind'], ['keyword_phrase', 'query']);
    }

    public function test_normalize_keeps_vietnamese_and_raw(): void
    {
        $norm = (new KeywordNormalizer())->normalize('  May  Balo Quà Tặng  ');
        self::assertSame('May  Balo Quà Tặng', $norm['raw_text']);
        self::assertSame('may balo quà tặng', $norm['normalized_text']);
        self::assertSame('may balo qua tang', $norm['folded_text']);
    }

    public function test_canonical_dedupe_keeps_accents(): void
    {
        $canon = new KeywordCanonicalizer();
        $normalizer = new KeywordNormalizer();
        $members = [];
        foreach (['May Túi Không Dệt', 'may túi không dệt', 'may  túi không dệt'] as $raw) {
            $members[] = $normalizer->normalize($raw);
        }
        self::assertTrue($canon->isNearDuplicate($members[0]['folded_text'], $members[1]['folded_text']));
        self::assertTrue($canon->isNearDuplicate($members[1]['folded_text'], $members[2]['folded_text']));
        $display = $canon->pickDisplay($members);
        self::assertSame('May Túi Không Dệt', $display);
        self::assertNotSame('may tui khong det', $display);
    }

    public function test_dictionary_excludes_garbage_and_limits_representatives(): void
    {
        $builder = new KeywordDictionaryBuilder();
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $rows[] = [
                'phrase_kind' => 'keyword_phrase',
                'is_seo_keyword' => true,
                'normalized_text' => 'túi canvas v'.$i,
                'cluster_key' => 'tui_canvas',
                'is_anchor_candidate' => true,
                'canonical_text' => 'túi canvas',
            ];
        }
        $rows[] = [
            'phrase_kind' => 'sentence',
            'is_seo_keyword' => false,
            'normalized_text' => 'công ty chúng tôi chuyên may túi canvas',
            'cluster_key' => 'tui_canvas',
        ];
        $rows[] = [
            'phrase_kind' => 'query',
            'is_seo_keyword' => true,
            'normalized_text' => 'mua túi canvas ở đâu',
            'cluster_key' => 'tui_canvas',
        ];
        $built = $builder->build($rows);
        self::assertSame('túi canvas', $built['clusters'][0]['primary']);
        self::assertLessThanOrEqual(5, count($built['clusters'][0]['variants']));
        self::assertNotContains('công ty chúng tôi chuyên may túi canvas', $built['clusters'][0]['variants']);
        self::assertContains('mua túi canvas ở đâu', $built['clusters'][0]['queries']);
    }

    public function test_anchor_eligibility_not_same_as_seo_flag(): void
    {
        $classifier = new KeywordRuleClassifier();
        $query = $classifier->classify('mua balo quà tặng ở đâu', 'mua balo quà tặng ở đâu');
        $phrase = $classifier->classify('balo quà tặng', 'balo quà tặng');
        self::assertTrue($query['is_seo_keyword']);
        self::assertFalse($query['is_anchor_candidate']);
        self::assertTrue($phrase['is_anchor_candidate']);
    }

    public function test_debouncer_bounds_jobs(): void
    {
        $d = new KeywordIntelligenceDebouncer();
        self::assertFalse($d->shouldDispatch(false, false, 0));
        self::assertTrue($d->shouldDispatch(true, false, 0));
        self::assertFalse($d->shouldDispatch(true, false, 1));
        self::assertSame(1, $d->jobsForChangedSet(500));
        self::assertSame(0, $d->jobsForChangedSet(0));
    }

    public function test_context_compression_respects_budget(): void
    {
        $rows = [];
        for ($i = 0; $i < 80; $i++) {
            $rows[] = [
                'phrase_kind' => 'keyword_phrase',
                'is_seo_keyword' => true,
                'normalized_text' => 'túi canvas '.$i,
                'cluster_key' => 'cluster_'.$i,
                'canonical_text' => 'túi canvas '.$i,
                'canonical_keyword_id' => $i,
                'seo_intent' => $i % 2 === 0 ? 'commercial' : 'informational',
            ];
        }
        $rows[] = [
            'phrase_kind' => 'sentence',
            'is_seo_keyword' => false,
            'normalized_text' => 'công ty chúng tôi may túi',
            'cluster_key' => 'garbage',
        ];
        $landscape = (new KeywordLandscapeBuilder())->build($rows);
        $ctx = (new KeywordGenerationContextBuilder())->build($landscape, [
            'max_topics' => 20,
            'max_exclusions' => 30,
        ]);
        $topics = count($ctx['core_topics']) + count($ctx['saturated_topics']) + count($ctx['weak_topics']);
        self::assertLessThanOrEqual(20, $topics);
        self::assertLessThanOrEqual(30, count($ctx['exclude_patterns']));
        $blob = json_encode($ctx);
        self::assertStringNotContainsString('công ty chúng tôi may túi', (string) $blob);
        self::assertNotEmpty($ctx['generation_rules']);
    }

    public function test_ai_duplicate_guard(): void
    {
        $guard = new KeywordAiCandidateGuard();
        $existing = [[
            'normalized_text' => 'túi canvas',
            'folded_text' => 'tui canvas',
            'cluster_key' => 'tui_canvas',
            'seo_intent' => 'commercial',
        ]];
        $out = $guard->evaluate([
            'túi canvas',
            'tui  canvas',
            'túi canvas sự kiện doanh nghiệp',
            'Công ty chúng tôi chuyên sản xuất balo theo yêu cầu',
        ], $existing);

        self::assertSame('reject', $out[0]['decision']);
        self::assertSame('exact_canonical', $out[0]['reason']);
        self::assertSame('reject', $out[1]['decision']);
        self::assertSame('accept', $out[2]['decision']);
        self::assertSame('reject', $out[3]['decision']);
        self::assertSame('sentence', $out[3]['reason']);
    }

    public function test_landscape_marks_saturated_and_unavailable_cannibalization(): void
    {
        $rows = [];
        for ($i = 0; $i < 25; $i++) {
            $rows[] = [
                'phrase_kind' => 'keyword_phrase',
                'is_seo_keyword' => true,
                'normalized_text' => 'túi canvas '.$i,
                'cluster_key' => 'tui_canvas',
                'canonical_text' => 'túi canvas',
                'canonical_keyword_id' => $i,
                'seo_intent' => 'commercial',
            ];
        }
        $landscape = (new KeywordLandscapeBuilder())->build($rows, [
            'tui_canvas' => ['target_pages' => 5, 'published' => 5, 'planned' => 0],
        ]);
        self::assertSame('saturated', $landscape['clusters'][0]['coverage']);
        self::assertSame('do_not_expand', $landscape['clusters'][0]['recommended_action']);
        self::assertSame('unavailable', $landscape['keyword_cannibalization']);
    }
}
