<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNotePromptSectionBuilder;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\ContentPlanningIntelligenceService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionOptions;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * SEO Audit Notes: MCP share ASC suggestions + DNA snapshot edit + prompt weight DESC.
 */
final class SeoAuditNotesContractTest extends TestCase
{
    public function test_mcp_share_asc_is_default_suggestion_sort(): void
    {
        $rows = [
            ['label' => 'D', 'topical_share' => 24.0, 'article_count' => 1],
            ['label' => 'A', 'topical_share' => 0.0, 'article_count' => 0],
            ['label' => 'C', 'topical_share' => 15.0, 'article_count' => 2],
            ['label' => 'B', 'topical_share' => 3.0, 'article_count' => 1],
        ];
        usort(
            $rows,
            static fn (array $a, array $b): int => KeywordClusterQuery::compareClusterRows($a, $b, 'mcp_share_asc'),
        );
        self::assertSame(['A', 'B', 'C', 'D'], array_column($rows, 'label'));
    }

    public function test_dna_snapshot_edit_does_not_require_cluster_mutation(): void
    {
        $initial = AuditNoteDnaNormalizer::snapshotFromClusterDna([
            ['value' => 'túi mỹ phẩm', 'count' => 18],
            ['value' => 'túi đựng mỹ phẩm', 'count' => 12],
            ['value' => 'du lịch', 'count' => 6],
        ]);
        self::assertSame(
            ['túi mỹ phẩm', 'túi đựng mỹ phẩm', 'du lịch'],
            array_column($initial, 'phrase'),
        );

        $afterRemove = AuditNoteDnaNormalizer::removeDna($initial, 'du lịch');
        $afterAdd = AuditNoteDnaNormalizer::addDna($afterRemove, 'ngăn chia mỹ phẩm', 8);

        self::assertSame(
            [
                ['phrase' => 'túi mỹ phẩm', 'weight' => 18, 'source' => 'cluster'],
                ['phrase' => 'túi đựng mỹ phẩm', 'weight' => 12, 'source' => 'cluster'],
                ['phrase' => 'ngăn chia mỹ phẩm', 'weight' => 8, 'source' => 'manual'],
            ],
            $afterAdd,
        );
        // Canonical cluster DNA untouched — we only mutated the snapshot array.
        self::assertCount(3, $initial);
        self::assertSame('du lịch', $initial[2]['phrase']);
    }

    public function test_duplicate_manual_dna_merges_keeping_higher_weight(): void
    {
        $dna = AuditNoteDnaNormalizer::snapshotFromClusterDna([
            ['value' => 'balo', 'count' => 5],
        ]);
        $dna = AuditNoteDnaNormalizer::addDna($dna, 'Balo', 3);
        self::assertCount(1, $dna);
        self::assertSame(5, $dna[0]['weight']);

        $dna = AuditNoteDnaNormalizer::addDna($dna, 'balo', 9);
        self::assertCount(1, $dna);
        self::assertSame(9, $dna[0]['weight']);
    }

    public function test_prompt_builder_orders_dna_by_weight_desc_and_skips_deleted(): void
    {
        $item = AuditNoteDnaNormalizer::normalizeNoteItem([
            'cluster_ref' => 'clu_tui',
            'cluster_name_snapshot' => 'Túi mỹ phẩm',
            'mcp_share_snapshot' => 0,
            'dna' => [
                ['phrase' => 'túi mỹ phẩm', 'weight' => 18, 'source' => 'cluster'],
                ['phrase' => 'túi đựng mỹ phẩm', 'weight' => 12, 'source' => 'cluster'],
                ['phrase' => 'ngăn chia mỹ phẩm', 'weight' => 8, 'source' => 'manual'],
            ],
        ]);
        self::assertNotNull($item);

        $lines = (new AuditNotePromptSectionBuilder)->lines([$item]);
        $joined = implode("\n", $lines);
        self::assertStringContainsString('18 túi mỹ phẩm', $joined);
        self::assertStringContainsString('12 túi đựng mỹ phẩm', $joined);
        self::assertStringContainsString('8 ngăn chia mỹ phẩm', $joined);
        self::assertStringNotContainsString('du lịch', $joined);

        $promptOrder = AuditNoteDnaNormalizer::promptLines($item['dna']);
        self::assertSame(
            ['18 túi mỹ phẩm', '12 túi đựng mỹ phẩm', '8 ngăn chia mỹ phẩm'],
            $promptOrder,
        );
    }

    public function test_manual_note_topic_uses_same_snapshot_shape(): void
    {
        $ref = AuditNoteDnaNormalizer::manualRef('Balo thủ công');
        self::assertTrue(AuditNoteDnaNormalizer::isManualRef($ref));

        $item = AuditNoteDnaNormalizer::normalizeNoteItem([
            'cluster_ref' => $ref,
            'cluster_name_snapshot' => 'Balo thủ công',
            'mcp_share_snapshot' => 0,
            'dna' => [
                ['phrase' => 'Balo thủ công', 'weight' => 1, 'source' => 'manual'],
            ],
        ]);
        self::assertNotNull($item);

        $lines = (new AuditNotePromptSectionBuilder)->lines([$item]);
        $joined = implode("\n", $lines);
        self::assertStringContainsString('manual', $joined);
        self::assertStringContainsString('1 Balo thủ công', $joined);
        self::assertStringNotContainsString('MCP 0.0%', $joined);
    }

    public function test_options_snapshot_persists_note_items(): void
    {
        $opts = NewContentSuggestionOptions::normalize([
            'quantity' => 10,
            'notes' => 'extra',
            'note_items' => [[
                'cluster_ref' => 'clu_a',
                'cluster_name_snapshot' => 'A',
                'mcp_share_snapshot' => 2.4,
                'dna' => [
                    ['phrase' => 'a', 'weight' => 3, 'source' => 'cluster'],
                ],
            ]],
        ]);
        self::assertCount(1, $opts['note_items']);
        self::assertSame('clu_a', $opts['note_items'][0]['cluster_ref']);
        self::assertSame(2.4, $opts['note_items'][0]['mcp_share_snapshot']);

        $snap = NewContentSuggestionOptions::snapshot($opts, 'vi');
        self::assertArrayHasKey('note_items', $snap);
        self::assertSame('clu_a', $snap['note_items'][0]['cluster_ref']);

        $restored = NewContentSuggestionOptions::fromSnapshot($snap);
        self::assertSame($opts['note_items'], $restored['note_items']);
    }

    public function test_render_brief_includes_note_dna_section(): void
    {
        $svc = new ContentPlanningIntelligenceService;
        $ctx = [
            'site' => ['id' => 1, 'domain' => 'example.com', 'primary_language' => 'vi'],
            'coverage' => ['covered' => 0, 'weakly_covered' => 0, 'uncovered' => 0, 'unknown' => 0],
            'principal_keywords' => [],
            'clusters' => [],
            'missing_directions' => [],
            'existing_topics' => [],
            'planned_topics' => [],
            'rejected_topics' => [],
            'mcp_signals' => [],
            'gsc_signals' => [],
            'mcp_period' => null,
            'covered_keyword_norms' => [],
            'planned_fingerprints' => [],
            'rejected_fingerprints' => [],
            'diagnostics' => [
                'principal_keywords_count' => 0,
                'cluster_count' => 0,
                'missing_direction_count' => 0,
                'mcp_period' => null,
                'covered_keyword_count' => 0,
            ],
        ];
        $brief = $svc->renderBrief($ctx, [
            'quantity' => 5,
            'note_items' => [[
                'cluster_ref' => 'clu_tui',
                'cluster_name_snapshot' => 'Túi mỹ phẩm',
                'mcp_share_snapshot' => 0,
                'dna' => [
                    ['phrase' => 'túi mỹ phẩm', 'weight' => 18, 'source' => 'cluster'],
                    ['phrase' => 'ngăn chia mỹ phẩm', 'weight' => 8, 'source' => 'manual'],
                ],
            ]],
            'notes' => '',
        ]);
        self::assertStringContainsString('Selected SEO Audit Notes', $brief);
        self::assertStringContainsString('18 túi mỹ phẩm', $brief);
        self::assertStringContainsString('8 ngăn chia mỹ phẩm', $brief);
        self::assertStringContainsString('MCP 0.0%', $brief);
    }

    public function test_ui_and_concern_wiring(): void
    {
        $concern = (string) (new ReflectionClass(
            \Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithAuditNotes::class,
        ))->getFileName();
        $page = (string) (new ReflectionClass(
            \Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner::class,
        ))->getFileName();
        $card = LegacyAddonPath::resolve('resources/views/components/content-project-new-content-card.blade.php');
        $blade = LegacyAddonPath::resolve('resources/views/components/content-project-audit-notes.blade.php');

        self::assertFileExists($concern);
        self::assertFileExists($blade);
        $pageSrc = (string) file_get_contents($page);
        self::assertStringContainsString('InteractsWithAuditNotes', $pageSrc);
        self::assertStringContainsString('mountInteractsWithAuditNotes', $pageSrc);

        $cardSrc = (string) file_get_contents($card);
        self::assertStringContainsString('content-project-audit-notes', $cardSrc);

        $bladeSrc = (string) file_get_contents($blade);
        self::assertStringContainsString('audit_notes_heading', $bladeSrc);
        self::assertStringContainsString('toggleAuditNoteCluster', $bladeSrc);
        self::assertStringContainsString('addAuditNoteDna', $bladeSrc);
        self::assertStringContainsString('removeAuditNoteDna', $bladeSrc);
        self::assertStringContainsString('addManualAuditNoteTopic', $bladeSrc);
        self::assertStringContainsString('$visibleRows', $bladeSrc);
        self::assertStringContainsString('wire:init="loadAuditNoteSuggestions"', $bladeSrc);
        self::assertStringContainsString('cp-audit-notes__skeleton', $bladeSrc);
        self::assertStringNotContainsString('is-selected', $bladeSrc);

        $cardFull = (string) file_get_contents($card);
        self::assertStringContainsString('cp-plan-sticky-cta', $cardFull);
        self::assertStringContainsString('data-planner-sticky-cta', $cardFull);
    }

    public function test_suggestion_query_defaults_to_mcp_share_asc_source(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteClusterSuggestionQuery::class,
            ))->getFileName(),
        );
        self::assertStringContainsString('mcp_share', $src);
        self::assertStringContainsString('SiteMcpClusterTopicalProfileBuilder', $src);
        self::assertStringContainsString('coverageForCluster', $src);
        self::assertStringNotContainsString('VocabularySuggest', $src);
        self::assertStringNotContainsString('Gsc', $src);
    }
}
