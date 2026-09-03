<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNotePromptSectionBuilder;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\DnaPlacement;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordDnaExtractor;
use Tests\TestCase;

final class DnaPlacementContractTest extends TestCase
{
    public function test_manual_add_defaults_to_after(): void
    {
        $dna = AuditNoteDnaNormalizer::addDna([], 'may');
        self::assertCount(1, $dna);
        self::assertSame('may', $dna[0]['phrase']);
        self::assertSame(DnaPlacement::AFTER, $dna[0]['placement']);
    }

    public function test_toggle_set_placement_before_persists_in_normalize(): void
    {
        $dna = AuditNoteDnaNormalizer::addDna([], 'may');
        $dna = AuditNoteDnaNormalizer::setDnaPlacement($dna, 'may', DnaPlacement::BEFORE);
        self::assertSame(DnaPlacement::BEFORE, $dna[0]['placement']);

        $item = AuditNoteDnaNormalizer::normalizeNoteItem([
            'cluster_ref' => 'clu_1',
            'cluster_name_snapshot' => 'Cặp laptop',
            'dna' => $dna,
            'target_dna_count' => 5,
        ]);
        self::assertNotNull($item);
        self::assertSame(DnaPlacement::BEFORE, $item['dna'][0]['placement']);
    }

    public function test_legacy_string_dna_hydrates_to_after(): void
    {
        $dna = AuditNoteDnaNormalizer::normalizeDnaList(['may', 'giá rẻ']);
        self::assertCount(2, $dna);
        $byPhrase = [];
        foreach ($dna as $row) {
            $byPhrase[$row['phrase']] = $row['placement'];
        }
        self::assertSame(DnaPlacement::AFTER, $byPhrase['may']);
        self::assertSame(DnaPlacement::AFTER, $byPhrase['giá rẻ']);
    }

    public function test_cluster_source_preserves_before(): void
    {
        $dna = AuditNoteDnaNormalizer::snapshotFromClusterDna([
            ['value' => 'may', 'placement' => 'before'],
            ['value' => 'giá rẻ'], // missing → after
        ]);
        $byPhrase = [];
        foreach ($dna as $row) {
            $byPhrase[$row['phrase']] = $row['placement'];
        }
        self::assertSame(DnaPlacement::BEFORE, $byPhrase['may']);
        self::assertSame(DnaPlacement::AFTER, $byPhrase['giá rẻ']);
    }

    public function test_missing_and_invalid_placement_normalize_after(): void
    {
        self::assertSame(DnaPlacement::AFTER, AuditNoteDnaNormalizer::normalizePlacement(null));
        self::assertSame(DnaPlacement::AFTER, AuditNoteDnaNormalizer::normalizePlacement(''));
        self::assertSame(DnaPlacement::AFTER, AuditNoteDnaNormalizer::normalizePlacement('prefix'));
        self::assertSame(DnaPlacement::BEFORE, AuditNoteDnaNormalizer::normalizePlacement(' BEFORE '));
        self::assertSame(DnaPlacement::AFTER, AuditNoteDnaNormalizer::normalizePlacement('After'));
    }

    public function test_dedupe_updates_placement_without_second_item(): void
    {
        $dna = AuditNoteDnaNormalizer::addDna([], 'May', null, 'manual', DnaPlacement::AFTER);
        $dna = AuditNoteDnaNormalizer::addDna($dna, 'may', null, 'manual', DnaPlacement::BEFORE);
        self::assertCount(1, $dna);
        self::assertSame(2, $dna[0]['slots']);
        self::assertSame(DnaPlacement::BEFORE, $dna[0]['placement']);
    }

    public function test_prompt_payload_includes_structured_placement(): void
    {
        $dna = AuditNoteDnaNormalizer::setDnaPlacement(
            AuditNoteDnaNormalizer::addDna([], 'may'),
            'may',
            DnaPlacement::BEFORE,
        );
        $structured = AuditNoteDnaNormalizer::structuredPromptDna($dna);
        self::assertSame([
            ['phrase' => 'may', 'placement' => 'before', 'slots' => 1],
        ], $structured);

        $lines = AuditNoteDnaNormalizer::promptLines($dna);
        self::assertStringContainsString('placement=before', $lines[0]);

        $builder = new AuditNotePromptSectionBuilder();
        $text = $builder->compactNotesText([
            [
                'cluster_ref' => 'clu_1',
                'cluster_name_snapshot' => 'Cặp laptop',
                'target_dna_count' => 5,
                'dna' => $dna,
            ],
        ]);
        self::assertStringContainsString('placement=before', $text);
        self::assertStringContainsString('placement=after → phrase is guided AFTER', $text);
        self::assertStringNotContainsString('"dna": ["may"]', $text);
    }

    public function test_semantic_split_batches_keep_phrase_with_placement(): void
    {
        $dna = AuditNoteDnaNormalizer::normalizeDnaList([
            ['phrase' => 'may', 'slots' => 2, 'placement' => 'before'],
            ['phrase' => 'giá rẻ', 'slots' => 1, 'placement' => 'after'],
        ]);
        // Simulate batch consume of 1 slot from first phrase.
        $left = 1;
        $remaining = [];
        foreach ($dna as $row) {
            $slots = (int) $row['slots'];
            if ($left > 0 && $slots <= $left) {
                $left -= $slots;

                continue;
            }
            if ($left > 0) {
                $remaining[] = array_merge($row, ['slots' => $slots - $left]);
                $left = 0;

                continue;
            }
            $remaining[] = $row;
        }
        self::assertCount(2, $remaining);
        self::assertSame('may', $remaining[0]['phrase']);
        self::assertSame('before', $remaining[0]['placement']);
        self::assertSame(1, $remaining[0]['slots']);
        self::assertSame('after', $remaining[1]['placement']);
    }

    public function test_ai_filled_dna_without_placement_defaults_after(): void
    {
        $dna = AuditNoteDnaNormalizer::normalizeDnaList([
            ['phrase' => 'công nghiệp', 'slots' => 1, 'source' => 'manual'],
        ]);
        self::assertSame(DnaPlacement::AFTER, $dna[0]['placement']);
    }

    public function test_per_item_independence(): void
    {
        $dna = AuditNoteDnaNormalizer::normalizeDnaList([
            ['phrase' => 'a', 'slots' => 1, 'placement' => 'after'],
            ['phrase' => 'b', 'slots' => 1, 'placement' => 'after'],
            ['phrase' => 'c', 'slots' => 1, 'placement' => 'after'],
        ]);
        $dna = AuditNoteDnaNormalizer::setDnaPlacement($dna, 'b', DnaPlacement::BEFORE);
        self::assertSame('after', $dna[0]['placement']);
        self::assertSame('before', $dna[1]['placement']);
        self::assertSame('after', $dna[2]['placement']);
        self::assertSame(3, AuditNoteDnaNormalizer::specifiedSlotCount($dna));
    }

    public function test_keywords_extractor_emits_before_after(): void
    {
        $extractor = app(KeywordDnaExtractor::class);
        $before = $extractor->extract('may cặp laptop', 'cặp laptop');
        $after = $extractor->extract('cặp laptop may', 'cặp laptop');

        $beforeMap = [];
        foreach ($before as $row) {
            $beforeMap[mb_strtolower($row['value'])] = $row['placement'];
        }
        $afterMap = [];
        foreach ($after as $row) {
            $afterMap[mb_strtolower($row['value'])] = $row['placement'];
        }

        self::assertArrayHasKey('may', $beforeMap);
        self::assertSame(DnaPlacement::BEFORE, $beforeMap['may']);
        self::assertArrayHasKey('may', $afterMap);
        self::assertSame(DnaPlacement::AFTER, $afterMap['may']);
    }

    public function test_ui_blade_has_placement_checkbox_and_persist_fields(): void
    {
        $path = dirname(__DIR__, 3).DIRECTORY_SEPARATOR
            .'seo-content-ai-compat'.DIRECTORY_SEPARATOR
            .'resources'.DIRECTORY_SEPARATOR
            .'views'.DIRECTORY_SEPARATOR
            .'components'.DIRECTORY_SEPARATOR
            .'content-project-audit-notes.blade.php';
        // content-projects/tests/Unit → ../../.. = omnichannel-addons
        $resolved = realpath($path);
        self::assertNotFalse($resolved, 'blade path missing: '.$path);
        $src = (string) file_get_contents($resolved);
        self::assertStringContainsString('setDnaPlacement', $src);
        self::assertStringContainsString('audit_notes_dna_placement_after', $src);
        self::assertStringContainsString('placement: normalizePlacement(row.placement)', $src);
        self::assertStringContainsString('DEFAULT_PLACEMENT', $src);
    }

    public function test_shared_constants_match_keywords_and_planner(): void
    {
        self::assertSame(DnaPlacement::BEFORE, AuditNoteDnaNormalizer::PLACEMENT_BEFORE);
        self::assertSame(DnaPlacement::AFTER, AuditNoteDnaNormalizer::PLACEMENT_AFTER);
        self::assertSame(DnaPlacement::DEFAULT, AuditNoteDnaNormalizer::DEFAULT_PLACEMENT);
    }
}
