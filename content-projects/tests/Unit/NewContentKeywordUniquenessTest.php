<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionDedupFilter;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionIdentity;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionParser;
use PHPUnit\Framework\TestCase;

/**
 * One canonical keyword → one Create suggestion (server-side).
 */
final class NewContentKeywordUniquenessTest extends TestCase
{
    public function test_same_keyword_same_case_keeps_first(): void
    {
        $out = $this->filter([
            ['keyword' => 'khóa kéo YKK', 'suggested_title' => 'So sánh khóa kéo YKK'],
            ['keyword' => 'khóa kéo YKK', 'suggested_title' => 'Khóa kéo YKK cho túi balo'],
        ]);

        self::assertCount(1, $out['accepted']);
        self::assertSame(1, $out['duplicate_skipped']);
        self::assertSame(1, $out['duplicate_breakdown']['in_batch_keyword']);
        self::assertSame(NewContentSuggestionDedupFilter::STATUS_ADDED, $out['results'][0]['status']);
        self::assertSame(
            NewContentSuggestionDedupFilter::STATUS_DUPLICATE_IN_BATCH_KEYWORD,
            $out['results'][1]['status'],
        );
    }

    public function test_same_keyword_case_variant(): void
    {
        $out = $this->filter([
            ['keyword' => 'Khóa kéo YKK', 'suggested_title' => 'Title A'],
            ['keyword' => 'KHÓA KÉO YKK', 'suggested_title' => 'Title B'],
        ]);

        self::assertCount(1, $out['accepted']);
        self::assertSame(1, $out['duplicate_breakdown']['in_batch_keyword']);
    }

    public function test_same_keyword_whitespace_variant(): void
    {
        $out = $this->filter([
            ['keyword' => ' khóa kéo YKK ', 'suggested_title' => 'Title A'],
            ['keyword' => 'khóa kéo YKK', 'suggested_title' => 'Title B'],
        ]);

        self::assertCount(1, $out['accepted']);
        self::assertSame(
            NewContentSuggestionIdentity::normalize(' khóa kéo YKK '),
            NewContentSuggestionIdentity::normalize('khóa kéo YKK'),
        );
    }

    public function test_unique_keywords_all_accepted(): void
    {
        $out = $this->filter([
            ['keyword' => 'khóa kéo YKK', 'suggested_title' => 'A'],
            ['keyword' => 'túi giữ nhiệt', 'suggested_title' => 'B'],
            ['keyword' => 'túi vải không dệt', 'suggested_title' => 'C'],
        ]);

        self::assertCount(3, $out['accepted']);
        self::assertSame(0, $out['duplicate_skipped']);
    }

    public function test_ki_only_keyword_remains_eligible(): void
    {
        // KI existence is NOT passed as coveredKeywordNorms — uncovered KI must add.
        $out = $this->filter([
            ['keyword' => 'khóa kéo YKK', 'suggested_title' => 'First'],
        ]);

        self::assertCount(1, $out['accepted']);
        self::assertSame(NewContentSuggestionDedupFilter::STATUS_ADDED, $out['results'][0]['status']);
    }

    public function test_ki_only_with_intra_batch_duplicate(): void
    {
        $out = $this->filter([
            ['keyword' => 'khóa kéo YKK', 'suggested_title' => 'First'],
            ['keyword' => 'khóa kéo YKK', 'suggested_title' => 'Second'],
        ]);

        self::assertCount(1, $out['accepted']);
        self::assertSame(NewContentSuggestionDedupFilter::STATUS_ADDED, $out['results'][0]['status']);
        self::assertSame(
            NewContentSuggestionDedupFilter::STATUS_DUPLICATE_IN_BATCH_KEYWORD,
            $out['results'][1]['status'],
        );
    }

    public function test_active_draft_keyword_blocks_second_article(): void
    {
        $out = $this->filter(
            [['keyword' => 'khóa kéo YKK', 'suggested_title' => 'New title']],
            plannedKw: [NewContentSuggestionIdentity::normalize('khóa kéo YKK') => true],
        );

        self::assertSame([], $out['accepted']);
        self::assertSame(1, $out['duplicate_breakdown']['active_draft']);
        self::assertSame(NewContentSuggestionDedupFilter::STATUS_DUPLICATE_DRAFT, $out['results'][0]['status']);
    }

    public function test_covered_content_still_blocks(): void
    {
        $out = $this->filter(
            [['keyword' => 'khóa kéo YKK', 'suggested_title' => 'T']],
            covered: [NewContentSuggestionIdentity::normalize('khóa kéo YKK') => true],
        );

        self::assertSame(
            NewContentSuggestionDedupFilter::STATUS_DUPLICATE_COVERED_CONTENT,
            $out['results'][0]['status'],
        );
    }

    public function test_rejected_stays_project_rejected(): void
    {
        $fp = NewContentSuggestionIdentity::fingerprint('khóa kéo YKK', 'Rejected title');
        $out = $this->filter(
            [['keyword' => 'khóa kéo YKK', 'suggested_title' => 'Rejected title']],
            rejected: [$fp => true],
        );

        self::assertSame(0, $out['duplicate_skipped']);
        self::assertSame(1, $out['rejected_skipped']);
        self::assertSame(NewContentSuggestionDedupFilter::STATUS_PROJECT_REJECTED, $out['results'][0]['status']);
    }

    public function test_result_summary_mix(): void
    {
        $rejectedFp = NewContentSuggestionIdentity::fingerprint('rej kw', 'R');
        $out = $this->filter(
            [
                ['keyword' => 'a', 'suggested_title' => 'A1'],
                ['keyword' => 'b', 'suggested_title' => 'B1'],
                ['keyword' => 'c', 'suggested_title' => 'C1'],
                ['keyword' => 'a', 'suggested_title' => 'A2'], // batch keyword dup
                ['keyword' => 'rej kw', 'suggested_title' => 'R'], // rejected
            ],
            rejected: [$rejectedFp => true],
        );

        self::assertCount(3, $out['accepted']);
        self::assertSame(1, $out['duplicate_skipped']);
        self::assertSame(1, $out['rejected_skipped']);
        self::assertSame(1, $out['duplicate_breakdown']['in_batch_keyword']);
        self::assertSame(0, $out['invalid'] ?? 0);
    }

    public function test_acceptance_example_three_suggestions_two_keywords(): void
    {
        $out = $this->filter([
            [
                'keyword' => 'khóa kéo YKK',
                'suggested_title' => 'So sánh khóa kéo YKK và khóa kéo thông thường',
            ],
            [
                'keyword' => 'khóa kéo YKK',
                'suggested_title' => 'Khóa kéo YKK cho túi balo: Ưu điểm và bảo trì',
            ],
            [
                'keyword' => 'túi giữ nhiệt',
                'suggested_title' => 'Túi giữ nhiệt: Công nghệ cách nhiệt và lợi ích',
            ],
        ]);

        self::assertCount(2, $out['accepted']);
        self::assertSame(1, $out['duplicate_skipped']);
        self::assertSame('khóa kéo YKK', $out['accepted'][0]['keyword']);
        self::assertSame('túi giữ nhiệt', $out['accepted'][1]['keyword']);
    }

    /**
     * @param  list<array<string, mixed>>  $raw
     * @param  array<string, true>  $plannedFp
     * @param  array<string, true>  $rejected
     * @param  array<string, true>  $covered
     * @param  array<string, true>  $plannedKw
     * @return array<string, mixed>
     */
    private function filter(
        array $raw,
        array $plannedFp = [],
        array $rejected = [],
        array $covered = [],
        array $plannedKw = [],
    ): array {
        $parsed = (new NewContentSuggestionParser)->parse($raw, max(1, count($raw)));

        return (new NewContentSuggestionDedupFilter)->filter(
            $parsed['candidates'],
            $plannedFp,
            $rejected,
            $covered,
            $plannedKw,
        );
    }
}
