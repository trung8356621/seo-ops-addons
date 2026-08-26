<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionDedupFilter;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionIdentity;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionParser;
use PHPUnit\Framework\TestCase;

/**
 * Dedup coverage semantics: KI inventory ≠ content coverage.
 */
final class NewContentDedupCoverageContractTest extends TestCase
{
    public function test_ki_phrase_without_coverage_is_accepted(): void
    {
        $out = $this->filterCandidates([
            ['keyword' => 'túi balo mở mỹ phẩm', 'suggested_title' => 'Title A'],
        ]);

        self::assertSame(1, count($out['accepted']));
        self::assertSame(0, $out['duplicate_skipped']);
        self::assertSame(NewContentSuggestionDedupFilter::STATUS_ADDED, $out['results'][0]['status']);
    }

    public function test_covered_content_blocks(): void
    {
        $out = $this->filterCandidates(
            [['keyword' => 'covered topic', 'suggested_title' => 'T']],
            covered: [NewContentSuggestionIdentity::normalize('covered topic') => true],
        );

        self::assertSame([], $out['accepted']);
        self::assertSame(1, $out['duplicate_skipped']);
        self::assertSame(1, $out['duplicate_breakdown']['covered_content']);
        self::assertSame(
            NewContentSuggestionDedupFilter::STATUS_DUPLICATE_COVERED_CONTENT,
            $out['results'][0]['status'],
        );
    }

    public function test_active_draft_fingerprint_and_keyword_norm_block(): void
    {
        $fp = NewContentSuggestionIdentity::fingerprint('draft kw', 'Draft Title');
        $out = $this->filterCandidates(
            [
                ['keyword' => 'draft kw', 'suggested_title' => 'Draft Title'],
                ['keyword' => 'draft kw', 'suggested_title' => 'Other Title'],
            ],
            plannedFp: [$fp => true],
            plannedKw: [NewContentSuggestionIdentity::normalize('draft kw') => true],
        );

        self::assertSame([], $out['accepted']);
        self::assertSame(2, $out['duplicate_skipped']);
        self::assertSame(2, $out['duplicate_breakdown']['active_draft']);
    }

    public function test_project_rejected_is_not_content_duplicate(): void
    {
        $fp = NewContentSuggestionIdentity::fingerprint('rej', 'R');
        $out = $this->filterCandidates(
            [['keyword' => 'rej', 'suggested_title' => 'R']],
            rejected: [$fp => true],
        );

        self::assertSame(0, $out['duplicate_skipped']);
        self::assertSame(1, $out['rejected_skipped']);
        self::assertSame(NewContentSuggestionDedupFilter::STATUS_PROJECT_REJECTED, $out['results'][0]['status']);
    }

    public function test_intra_batch_duplicate_keeps_one(): void
    {
        $fp = NewContentSuggestionIdentity::fingerprint('same', 'Same Title');
        $candidates = [
            [
                'keyword' => 'same',
                'title' => 'Same Title',
                'fingerprint' => $fp,
                'suggestion_reason' => '',
                'source_signal' => 'cluster_gap',
            ],
            [
                'keyword' => 'same',
                'title' => 'Same Title',
                'fingerprint' => $fp,
                'suggestion_reason' => '',
                'source_signal' => 'cluster_gap',
            ],
        ];

        $out = (new NewContentSuggestionDedupFilter)->filter($candidates, [], [], [], []);

        self::assertCount(1, $out['accepted']);
        self::assertSame(1, $out['duplicate_skipped']);
        self::assertSame(1, $out['duplicate_breakdown']['in_batch']);
    }

    public function test_twenty_uncovered_ki_topics_all_accepted(): void
    {
        $candidates = [];
        for ($i = 1; $i <= 20; $i++) {
            $candidates[] = [
                'keyword' => 'uncovered topic '.$i,
                'suggested_title' => 'Title '.$i,
            ];
        }

        $out = $this->filterCandidates($candidates);

        self::assertCount(20, $out['accepted']);
        self::assertSame(0, $out['duplicate_skipped']);
        self::assertSame(0, $out['rejected_skipped']);
    }

    /**
     * @param  list<array<string, mixed>>  $raw
     * @param  array<string, true>  $plannedFp
     * @param  array<string, true>  $rejected
     * @param  array<string, true>  $covered
     * @param  array<string, true>  $plannedKw
     * @return array<string, mixed>
     */
    private function filterCandidates(
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
