<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentClusterAttributionValidator;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionStructuredResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\PlanningMonthBackfill;
use PHPUnit\Framework\TestCase;

/**
 * Behavior tests for note-driven cluster_ref / dna_phrases attribution (no DB).
 */
final class NewContentClusterAttributionValidatorTest extends TestCase
{
    public function test_multi_cluster_accepts_valid_refs_and_keeps_dna_per_cluster(): void
    {
        $validator = new NewContentClusterAttributionValidator;
        $noteItems = [
            ['cluster_ref' => 'clu_a', 'cluster_name_snapshot' => 'Topic A', 'dna' => [['phrase' => 'dna-a', 'slots' => 1]]],
            ['cluster_ref' => 'clu_b', 'cluster_name_snapshot' => 'Topic B', 'dna' => [['phrase' => 'dna-b', 'slots' => 1]]],
        ];
        $candidates = [
            [
                'keyword' => 'kw-a',
                'suggested_title' => 'Title A',
                'cluster_ref' => 'clu_a',
                'dna_phrases' => ['dna-a', 'angle-a'],
            ],
            [
                'keyword' => 'kw-b',
                'suggested_title' => 'Title B',
                'cluster_ref' => 'clu_b',
                'dna_phrases' => ['dna-b'],
            ],
        ];

        $gate = $validator->filter($candidates, $noteItems);

        self::assertTrue($gate['requires_attribution']);
        self::assertTrue($gate['multi_cluster']);
        self::assertCount(2, $gate['accepted']);
        self::assertSame([], $gate['rejected']);
        self::assertSame('clu_a', $gate['accepted'][0]['cluster_ref']);
        self::assertSame(['dna-a', 'angle-a'], $gate['accepted'][0]['dna_phrases']);
        self::assertSame('clu_b', $gate['accepted'][1]['cluster_ref']);
        self::assertSame(['dna-b'], $gate['accepted'][1]['dna_phrases']);
        self::assertNotContains('dna-a', $gate['accepted'][1]['dna_phrases']);
    }

    public function test_multi_cluster_rejects_unknown_and_missing_refs(): void
    {
        $validator = new NewContentClusterAttributionValidator;
        $noteItems = [
            ['cluster_ref' => 'clu_a', 'cluster_name_snapshot' => 'A'],
            ['cluster_ref' => 'clu_b', 'cluster_name_snapshot' => 'B'],
        ];

        $gate = $validator->filter([
            ['keyword' => 'x', 'cluster_ref' => 'invented', 'dna_phrases' => ['z']],
            ['keyword' => 'y', 'cluster_ref' => '', 'dna_phrases' => ['z']],
            ['keyword' => 'ok', 'cluster_ref' => 'clu_a', 'dna_phrases' => ['a']],
        ], $noteItems);

        self::assertCount(1, $gate['accepted']);
        self::assertCount(2, $gate['rejected']);
        self::assertSame('clu_a', $gate['accepted'][0]['cluster_ref']);
    }

    public function test_single_cluster_fallback_fills_missing_ref(): void
    {
        $validator = new NewContentClusterAttributionValidator;
        $gate = $validator->filter(
            [['keyword' => 'solo', 'suggested_title' => 'Solo', 'cluster_ref' => '', 'dna_phrases' => ['one']]],
            [['cluster_ref' => 'only_one', 'cluster_name_snapshot' => 'Only']],
        );

        self::assertFalse($gate['multi_cluster']);
        self::assertCount(1, $gate['accepted']);
        self::assertSame('only_one', $gate['accepted'][0]['cluster_ref']);
        self::assertSame(['one'], $gate['accepted'][0]['dna_phrases']);
    }

    public function test_no_note_items_allows_unattributed_backward_compat(): void
    {
        $validator = new NewContentClusterAttributionValidator;
        $gate = $validator->filter(
            [['keyword' => 'legacy', 'suggested_title' => 'Legacy']],
            [],
        );

        self::assertFalse($gate['requires_attribution']);
        self::assertCount(1, $gate['accepted']);
        self::assertSame([], $gate['rejected']);
    }

    public function test_structured_output_contract_requires_attribution_fields(): void
    {
        $footer = NewContentSuggestionStructuredResult::outputContractFooter('post', 3, ['clu_a', 'clu_b']);
        self::assertStringContainsString('cluster_ref', $footer);
        self::assertStringContainsString('dna_phrases', $footer);
        self::assertStringContainsString('clu_a', $footer);
        self::assertStringContainsString('clu_b', $footer);

        $plain = NewContentSuggestionStructuredResult::outputContractFooter('post', 2, []);
        self::assertStringNotContainsString('ATTRIBUTION REQUIRED', $plain);

        $repair = NewContentSuggestionStructuredResult::repairBrief('[]', 'post', 2, ['clu_a']);
        self::assertStringContainsString('clu_a', $repair);
        self::assertStringContainsString('cluster_ref', $repair);
    }

    public function test_planning_month_ssot_draft_prefers_created_at_over_target_date(): void
    {
        self::assertSame('2026-03', PlanningMonthBackfill::resolve([
            'planning_month' => null,
            'created_at' => '2026-03-15 10:00:00',
            'target_date' => '2026-04-01',
            'project_is_draft' => true,
        ]));
        self::assertSame('2026-08', PlanningMonthBackfill::resolve([
            'planning_month' => null,
            'project_month' => '2026-08-01',
            'created_at' => '2026-01-01',
            'target_date' => '2026-12-01',
            'project_is_draft' => false,
        ]));
    }
}
