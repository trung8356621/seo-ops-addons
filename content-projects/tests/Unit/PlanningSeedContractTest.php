<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithAuditNotes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNotePromptSectionBuilder;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteTargetAllocator;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentAutoDnaPolicy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Planning Seed (manual_seed): free-form semantic anchor — no existing Topic/MCP required.
 */
final class PlanningSeedContractTest extends TestCase
{
    public function test_normalize_manual_seed_shape(): void
    {
        $item = AuditNoteDnaNormalizer::normalizeNoteItem([
            'source_type' => 'manual_seed',
            'cluster_ref' => 'manual:abc123',
            'seed_text' => 'Thời trang, cách phối đồ với balo',
            'target_dna_count' => 10,
            'dna' => [],
        ]);

        self::assertNotNull($item);
        self::assertSame(AuditNoteDnaNormalizer::SOURCE_TYPE_MANUAL_SEED, $item['source_type']);
        self::assertSame('Thời trang, cách phối đồ với balo', $item['seed_text']);
        self::assertSame('Thời trang, cách phối đồ với balo', $item['cluster_name_snapshot']);
        self::assertNull($item['mcp_share_snapshot']);
        self::assertSame(10, $item['target_dna_count']);
        self::assertSame(AuditNoteTargetAllocator::TARGET_MODE_MANUAL, $item['target_mode']);
        self::assertSame([], $item['dna']);
        self::assertTrue(AuditNoteDnaNormalizer::isManualSeed($item));
    }

    public function test_manual_seed_does_not_require_cluster_and_rejects_blank_seed(): void
    {
        self::assertNull(AuditNoteDnaNormalizer::normalizeNoteItem([
            'source_type' => 'manual_seed',
            'cluster_ref' => 'manual:x',
            'seed_text' => '   ',
            'dna' => [],
        ]));

        $ref = AuditNoteDnaNormalizer::manualSeedRef();
        self::assertStringStartsWith('manual:', $ref);
        self::assertNotSame('manual:untitled', $ref);
    }

    public function test_manual_seed_contributes_to_total_ideas_and_slot_math(): void
    {
        $seed = AuditNoteDnaNormalizer::normalizeNoteItem([
            'source_type' => 'manual_seed',
            'cluster_ref' => 'manual:seed1',
            'seed_text' => 'Thời trang, cách phối đồ với balo',
            'target_dna_count' => 10,
            'dna' => [],
        ]);
        self::assertNotNull($seed);

        $dna = AuditNoteDnaNormalizer::addDna([], 'phong cách công sở');
        $dna = AuditNoteDnaNormalizer::addDna($dna, 'phối balo với váy');
        $seed['dna'] = $dna;
        $seed['target_dna_count'] = AuditNoteDnaNormalizer::ensureTargetCoversSpecified(10, $dna);

        self::assertSame(2, AuditNoteDnaNormalizer::specifiedSlotCount($seed['dna']));
        self::assertSame(8, AuditNoteDnaNormalizer::missingSlotCount(10, $seed['dna']));

        $cluster = AuditNoteDnaNormalizer::normalizeNoteItem([
            'source_type' => 'cluster',
            'cluster_ref' => 'clu_a',
            'cluster_name_snapshot' => 'A',
            'mcp_share_snapshot' => 2.0,
            'target_dna_count' => 7,
            'dna' => [],
        ]);
        self::assertSame(17, AuditNoteDnaNormalizer::totalTargetDnaCount([$cluster, $seed]));
    }

    public function test_allocator_keeps_manual_seed_out_of_mcp_pool(): void
    {
        $items = [
            AuditNoteDnaNormalizer::normalizeNoteItem([
                'source_type' => 'manual_seed',
                'cluster_ref' => 'manual:seed',
                'seed_text' => 'Seed fashion',
                'target_dna_count' => 10,
                'dna' => [],
            ]),
            AuditNoteDnaNormalizer::normalizeNoteItem([
                'source_type' => 'cluster',
                'cluster_ref' => 'clu_a',
                'cluster_name_snapshot' => 'A',
                'mcp_share_snapshot' => 10,
                'target_dna_count' => 5,
                'target_mode' => 'auto',
                'dna' => [],
            ]),
        ];

        $result = AuditNoteTargetAllocator::apply(array_values(array_filter($items)), 15);
        self::assertSame(AuditNoteTargetAllocator::CODE_OK, $result['code']);
        $byRef = [];
        foreach ($result['items'] as $item) {
            $byRef[$item['cluster_ref']] = $item;
        }
        self::assertSame(10, $byRef['manual:seed']['target_dna_count']);
        self::assertSame(AuditNoteTargetAllocator::TARGET_MODE_MANUAL, $byRef['manual:seed']['target_mode']);
        self::assertSame(5, $byRef['clu_a']['target_dna_count']);
    }

    public function test_system_policy_mentions_manual_seed_and_new_keywords(): void
    {
        $policy = new NewContentAutoDnaPolicy;
        self::assertSame(3, NewContentAutoDnaPolicy::VERSION);

        $noteItems = [[
            'source_type' => 'manual_seed',
            'cluster_ref' => 'manual:seed',
            'seed_text' => 'Thời trang, cách phối đồ với balo',
            'target_dna_count' => 10,
            'dna' => [],
        ]];
        $meta = $policy->metadata(10, $noteItems);
        self::assertSame(1, $meta['manual_seed_count']);
        self::assertSame(0, $meta['cluster_count']);

        $lines = implode("\n", $policy->instructionLines(10, $noteItems));
        self::assertStringContainsString('source_type=manual_seed', $lines);
        self::assertStringContainsString('NEW keyword', $lines);
        self::assertStringContainsString('SeoTopicClusterMeta', $lines);

        $prompt = implode("\n", (new AuditNotePromptSectionBuilder)->lines($noteItems));
        self::assertStringContainsString('Planning Seed', $prompt);
        self::assertStringContainsString('Thời trang, cách phối đồ với balo', $prompt);

        $history = implode("\n", (new AuditNotePromptSectionBuilder)->historyLines($noteItems));
        self::assertStringContainsString('Seed:', $history);
        self::assertStringContainsString('AI fill 10', $history);
    }

    public function test_ui_is_client_first_planning_seed_form(): void
    {
        $blade = LegacyAddonPath::read('resources/views/components/content-project-audit-notes.blade.php');
        $concern = (string) file_get_contents(
            (string) (new ReflectionClass(InteractsWithAuditNotes::class))->getFileName(),
        );

        self::assertStringContainsString('data-planning-seed-form="1"', $blade);
        self::assertStringContainsString('addManualSeed()', $blade);
        self::assertStringContainsString("source_type: 'manual_seed'", $blade);
        self::assertStringContainsString('STORAGE_VERSION = 5', $blade);
        self::assertStringNotContainsString('wire:click="addManualAuditNoteTopic"', $blade);
        self::assertStringContainsString('SOURCE_TYPE_MANUAL_SEED', $concern);
        self::assertStringContainsString('manualSeedRef()', $concern);
        self::assertStringContainsString("'dna' => []", $concern);
    }

    public function test_legacy_default_weight_unchanged_and_cluster_fallback_intact(): void
    {
        self::assertSame(1, AuditNoteDnaNormalizer::DEFAULT_WEIGHT);
        self::assertSame(5, AuditNoteDnaNormalizer::DEFAULT_TARGET_DNA_COUNT);
        $dna = AuditNoteDnaNormalizer::snapshotFromClusterDna([['value' => 'balo']]);
        self::assertSame(1, $dna[0]['slots']);
    }
}
