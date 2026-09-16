<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleInternalLinkPriorityMerger;
use Omnichannel\Addons\Content\Services\ArticleLinkSuggestionCandidateRetriever;
use Omnichannel\Addons\Content\Support\InternalLinkDestinationGate;
use Omnichannel\Addons\Content\Support\UnresolvedInternalLinkSuggestion;
use Omnichannel\Addons\Seo\Support\LinkSuggestionValidator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Destination failure must downgrade to href="#", never drop a valid anchor.
 */
final class DestinationDowngradeContractTest extends TestCase
{
    private const CTX = [
        'site_domain' => 'shop.test',
        'site_id' => 99,
        'current_article_id' => 1,
        'current_urls' => ['https://shop.test/current'],
        'current_slug' => 'current',
    ];

    public function test_unresolved_helper_shape(): void
    {
        $row = UnresolvedInternalLinkSuggestion::make('túi canvas', [
            'keyword_id' => 7,
            'target_article_id' => 99,
            'score' => 80,
            'match_reason' => 'title_match',
            'destination_reject_reason' => 'destination_validator_reject',
        ]);

        self::assertSame('#', $row['href']);
        self::assertNull($row['target_url']);
        self::assertFalse($row['destination_resolved']);
        self::assertTrue($row['can_insert']);
        self::assertSame(99, $row['target_article_id']);
        self::assertSame('destination_validator_reject', $row['provenance']['destination_reject_reason']);
    }

    public function test_case1_no_candidate_emits_hash(): void
    {
        $row = UnresolvedInternalLinkSuggestion::make('anchor alpha', [
            'keyword_id' => 1,
            'match_reason' => 'no_article_candidate',
            'destination_reject_reason' => 'no_article_candidate',
        ]);
        $merged = (new ArticleInternalLinkPriorityMerger)->merge([
            ArticleInternalLinkPriorityMerger::STAGE_GENERIC => [$row],
        ]);

        self::assertCount(1, $merged);
        self::assertSame('#', $merged[0]['href']);
        self::assertNull($merged[0]['target_url']);
        self::assertFalse($merged[0]['destination_resolved']);
    }

    public function test_case2_invalid_url_downgrades(): void
    {
        $gate = InternalLinkDestinationGate::evaluate(
            '',
            false,
            ['text' => 'túi canvas', 'target_article_id' => 60, 'bucket' => 'internal'],
            self::CTX,
        );

        self::assertFalse($gate['destination_resolved']);
        self::assertSame('#', $gate['href']);
        self::assertNull($gate['url']);
        self::assertSame('destination_unresolved', $gate['destination_reject_reason']);
    }

    public function test_case3_self_link_downgrades(): void
    {
        $gate = InternalLinkDestinationGate::evaluate(
            'https://shop.test/current',
            true,
            ['text' => 'túi canvas', 'target_article_id' => 1, 'bucket' => 'internal'],
            self::CTX,
        );

        self::assertFalse($gate['destination_resolved']);
        self::assertSame('#', $gate['href']);
        self::assertNull($gate['url']);
        self::assertSame('destination_validator_reject', $gate['destination_reject_reason']);
    }

    public function test_case4_wrong_domain_bucket_via_validator_downgrades(): void
    {
        $gate = InternalLinkDestinationGate::evaluate(
            'https://other.example/page',
            true,
            ['text' => 'túi canvas', 'target_article_id' => 0, 'bucket' => 'internal'],
            self::CTX,
        );

        self::assertFalse($gate['destination_resolved']);
        self::assertSame('#', $gate['href']);
        self::assertSame('destination_validator_reject', $gate['destination_reject_reason']);
    }

    public function test_case5_validator_reject_downgrades(): void
    {
        $gate = InternalLinkDestinationGate::evaluate(
            'not-a-url',
            true,
            ['text' => 'túi canvas', 'target_article_id' => 9, 'bucket' => 'internal'],
            self::CTX,
        );

        // index claims resolved but URL unparsable → unresolved / invalid.
        self::assertFalse($gate['destination_resolved']);
        self::assertSame('#', $gate['href']);
    }

    public function test_case6_resolved_valid_url_kept(): void
    {
        $gate = InternalLinkDestinationGate::evaluate(
            'https://shop.test/good-target',
            true,
            ['text' => 'túi canvas', 'target_article_id' => 80, 'bucket' => 'internal'],
            self::CTX,
        );

        self::assertTrue($gate['destination_resolved']);
        self::assertSame('https://shop.test/good-target', $gate['href']);
        self::assertSame('https://shop.test/good-target', $gate['url']);
        self::assertNull($gate['destination_reject_reason']);
    }

    public function test_case7_three_unresolved_distinct_anchors_survive_final_merge(): void
    {
        $rows = [
            UnresolvedInternalLinkSuggestion::make('anchor one', ['keyword_id' => 1]),
            UnresolvedInternalLinkSuggestion::make('anchor two', ['keyword_id' => 2]),
            UnresolvedInternalLinkSuggestion::make('anchor three', ['keyword_id' => 3]),
        ];
        $merged = (new ArticleInternalLinkPriorityMerger)->merge([
            ArticleInternalLinkPriorityMerger::STAGE_GENERIC => $rows,
        ]);

        self::assertCount(3, $merged);
        foreach ($merged as $row) {
            self::assertSame('#', $row['href']);
            self::assertNull($row['target_url']);
            self::assertFalse($row['destination_resolved']);
        }
    }

    public function test_case8_same_unresolved_anchor_dedupes_once(): void
    {
        $merged = (new ArticleInternalLinkPriorityMerger)->merge([
            ArticleInternalLinkPriorityMerger::STAGE_GENERIC => [
                UnresolvedInternalLinkSuggestion::make('túi vải bố', ['keyword_id' => 1]),
                UnresolvedInternalLinkSuggestion::make('Túi Vải Bố', ['keyword_id' => 2]),
            ],
        ]);

        self::assertCount(1, $merged);
    }

    public function test_case9_real_url_dedupe_unchanged(): void
    {
        $merged = (new ArticleInternalLinkPriorityMerger)->merge([
            ArticleInternalLinkPriorityMerger::STAGE_GENERIC => [
                [
                    'text' => 'a',
                    'href' => 'https://shop.test/x',
                    'destination_resolved' => true,
                    'score' => 90,
                ],
                [
                    'text' => 'b',
                    'href' => 'https://shop.test/x/',
                    'destination_resolved' => true,
                    'score' => 80,
                ],
            ],
        ]);

        self::assertCount(1, $merged);
        self::assertSame('a', $merged[0]['text']);
    }

    public function test_case10_anchor_level_invalid_still_dropped(): void
    {
        self::assertFalse(LinkSuggestionValidator::isUsableAnchorCandidate(['text' => '']));
        self::assertFalse(LinkSuggestionValidator::isUsableAnchorCandidate([
            'text' => 'https://example.com/x',
        ]));
    }

    public function test_case11_already_linked_anchor_label_still_dropped_at_merge(): void
    {
        $merged = (new ArticleInternalLinkPriorityMerger)->merge(
            [
                ArticleInternalLinkPriorityMerger::STAGE_GENERIC => [
                    UnresolvedInternalLinkSuggestion::make('đã link rồi', ['keyword_id' => 1]),
                ],
            ],
            alreadyLinkedNormalizedUrls: [],
            alreadyLinkedLabels: ['đã link rồi'],
        );

        self::assertCount(0, $merged);
    }

    public function test_already_linked_destination_downgrades_not_skips(): void
    {
        $gate = InternalLinkDestinationGate::evaluate(
            'https://shop.test/occupied',
            true,
            ['text' => 'túi canvas', 'target_article_id' => 70, 'bucket' => 'internal'],
            self::CTX,
            ['shop.test/occupied'],
        );

        self::assertFalse($gate['destination_resolved']);
        self::assertSame('#', $gate['href']);
        self::assertSame('already_linked_destination', $gate['destination_reject_reason']);
    }

    public function test_retriever_uses_destination_gate_not_continue_drop(): void
    {
        $body = $this->methodBody(ArticleLinkSuggestionCandidateRetriever::class, 'resolveBestForAnchors');
        self::assertStringContainsString('InternalLinkDestinationGate::evaluate', $body);
        self::assertStringNotContainsString('if (! LinkSuggestionValidator::isValidLinkSuggestion', $body);
    }

    public function test_pipeline_generic_emits_hash_when_retriever_misses_keyword(): void
    {
        $pipelineSrc = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\Content\Services\ArticleInternalLinkPipeline::class))->getFileName(),
        );

        self::assertStringContainsString('UnresolvedInternalLinkSuggestion::make', $pipelineSrc);
        self::assertStringContainsString('no_article_candidate', $pipelineSrc);
        self::assertStringContainsString('destination_validator_reject', $pipelineSrc);
        self::assertStringContainsString('needGenericSearch[]', $pipelineSrc);
        self::assertStringNotContainsString(
            "if (! is_array(\$resolved)) {\n                continue;",
            $pipelineSrc,
        );
    }

    public function test_search_ranked_still_requires_resolved_destination(): void
    {
        $method = $this->methodBody(ArticleLinkSuggestionCandidateRetriever::class, 'searchRanked');
        self::assertStringContainsString('destination_resolved', $method);
        self::assertStringContainsString('isParsableTarget', $method);
        self::assertStringNotContainsString("'#'", $method);
    }

    private function methodBody(string $class, string $method): string
    {
        $ref = new ReflectionMethod($class, $method);
        $file = (string) $ref->getFileName();
        $start = (int) $ref->getStartLine();
        $end = (int) $ref->getEndLine();
        $lines = file($file);
        if ($lines === false) {
            return '';
        }

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
