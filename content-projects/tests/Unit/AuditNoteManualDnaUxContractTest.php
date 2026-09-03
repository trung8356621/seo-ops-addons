<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithAuditNotes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Client-first DNA slots UX: target_dna_count, Enter add, duplicate, no per-DNA weight.
 */
final class AuditNoteManualDnaUxContractTest extends TestCase
{
    public function test_target_default_is_five_and_legacy_weight_constant_retained(): void
    {
        self::assertSame(1, AuditNoteDnaNormalizer::DEFAULT_WEIGHT);
        self::assertSame(5, AuditNoteDnaNormalizer::DEFAULT_MANUAL_WEIGHT);
        self::assertSame(5, AuditNoteDnaNormalizer::DEFAULT_TARGET_DNA_COUNT);
    }

    public function test_cluster_snapshot_unique_phrases_are_one_slot_each(): void
    {
        $dna = AuditNoteDnaNormalizer::snapshotFromClusterDna([
            ['value' => 'balo', 'count' => 18],
            ['value' => 'quà tặng', 'weight' => 7],
        ]);
        self::assertCount(2, $dna);
        self::assertSame(1, $dna[0]['slots']);
        self::assertSame(1, $dna[1]['slots']);
        self::assertArrayNotHasKey('weight', $dna[0]);
    }

    public function test_server_concern_has_no_shared_dna_micro_edit_round_trips(): void
    {
        $concern = (string) file_get_contents(
            (string) (new ReflectionClass(InteractsWithAuditNotes::class))->getFileName(),
        );

        self::assertStringNotContainsString('auditNoteDnaPhrase', $concern);
        self::assertStringNotContainsString('auditNoteDnaWeight', $concern);
        self::assertStringNotContainsString('function addAuditNoteDna', $concern);
        self::assertStringNotContainsString('function removeAuditNoteDna', $concern);
        self::assertStringContainsString('target_dna_count', $concern);
        self::assertStringContainsString('cp-audit-notes-selected', $concern);
    }

    public function test_blade_uses_client_side_slot_editor_without_wire_dna_bindings(): void
    {
        $blade = LegacyAddonPath::read('resources/views/components/content-project-audit-notes.blade.php');

        self::assertStringContainsString('cpAuditNotesRoot', $blade);
        self::assertStringContainsString("Alpine.store('cpAuditNotes'", $blade);
        self::assertStringContainsString('addDna(item.cluster_ref)', $blade);
        self::assertStringContainsString('duplicateDna(', $blade);
        self::assertStringContainsString('removeDnaSlot(', $blade);
        self::assertStringContainsString('audit_notes_target_dna', $blade);
        self::assertStringContainsString('seoOps:content-planner:audit-notes:v2:site:', $blade);
        self::assertStringContainsString('resetTargetAuto(', $blade);
        self::assertStringContainsString('rebalanceAutoTargets', $blade);
        self::assertStringContainsString('target_mode', $blade);
        self::assertStringNotContainsString('audit_notes_mode_auto', $blade);
        self::assertStringNotContainsString("item.dna_mode === 'auto'", $blade);
        self::assertStringNotContainsString('$wire.addAuditNoteDna', $blade);
        self::assertStringNotContainsString('wire:click="addAuditNoteDna', $blade);
        self::assertStringNotContainsString('wire:click="removeAuditNoteDna', $blade);
        self::assertStringNotContainsString('wire:model="auditNoteDnaPhrase"', $blade);
        self::assertStringNotContainsString('wire:model="auditNoteDnaWeight"', $blade);
        self::assertStringNotContainsString('wire:model.live="auditNoteDnaPhrase"', $blade);
        self::assertStringNotContainsString('cp-audit-notes__dna-weight-input"\n                            x-model="draftFor', $blade);
    }

    public function test_generate_card_sends_client_snapshot(): void
    {
        $card = LegacyAddonPath::read('resources/views/components/content-project-new-content-card.blade.php');

        self::assertStringContainsString("Alpine.store('cpAuditNotes')", $card);
        self::assertStringContainsString('generateNewContentSuggestions(items)', $card);
        self::assertStringNotContainsString('wire:click="generateNewContentSuggestions"', $card);
    }
}
