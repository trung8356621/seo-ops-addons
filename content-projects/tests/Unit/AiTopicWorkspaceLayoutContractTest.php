<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;
use Tests\Support\ProjectRoot;

/**
 * AI New Content two-column Topic workspace UX contracts.
 */
final class AiTopicWorkspaceLayoutContractTest extends TestCase
{
    public function test_total_ideas_lives_in_selected_header_not_top_input(): void
    {
        $card = LegacyAddonPath::read('resources/views/components/content-project-new-content-card.blade.php');
        $notes = LegacyAddonPath::read('resources/views/components/content-project-audit-notes.blade.php');

        self::assertStringNotContainsString('data-planner-ideas-total="1"', $card);
        self::assertStringNotContainsString('wire:model="newContentQuantity"', $card);
        self::assertStringContainsString('data-planner-ideas-total="1"', $notes);
        self::assertStringContainsString('cp-audit-notes__ideas-total', $notes);
        self::assertStringContainsString('stickyTotal()', $notes);
        self::assertStringNotContainsString('<input', substr(
            $notes,
            (int) strpos($notes, 'cp-audit-notes__ideas-total'),
            400,
        ) ?: '');
    }

    public function test_desktop_two_column_workspace_exists(): void
    {
        $notes = LegacyAddonPath::read('resources/views/components/content-project-audit-notes.blade.php');
        $styles = LegacyAddonPath::read('resources/views/components/content-project-ops-styles.blade.php');

        self::assertStringContainsString('cp-ai-topic-workspace', $notes);
        self::assertStringContainsString('data-ai-topic-column="available"', $notes);
        self::assertStringContainsString('data-ai-topic-column="selected"', $notes);
        self::assertStringContainsString('cp-ai-topic-column__body', $notes);
        self::assertMatchesRegularExpression(
            '/@media \(min-width: 1024px\)[\s\S]*?grid-template-columns:\s*minmax\(0,\s*1fr\)\s+minmax\(0,\s*1fr\)/',
            $styles,
        );
        self::assertMatchesRegularExpression(
            '/@media \(max-width: 1023px\)[\s\S]*?\.cp-ai-topic-column--available/',
            $styles,
        );
    }

    public function test_selected_topics_excluded_from_available_list(): void
    {
        $notes = LegacyAddonPath::read('resources/views/components/content-project-audit-notes.blade.php');

        self::assertStringContainsString('$selectedMap', $notes);
        self::assertStringContainsString('$visibleRows', $notes);
        self::assertStringContainsString('x-show="!isSelected(', $notes);
        self::assertStringContainsString('toggleAuditNoteCluster', $notes);
        self::assertStringContainsString('removeTopic(item.cluster_ref)', $notes);
    }

    public function test_no_per_dna_livewire_bindings_and_cta_outside_workspace(): void
    {
        $notes = LegacyAddonPath::read('resources/views/components/content-project-audit-notes.blade.php');
        $card = LegacyAddonPath::read('resources/views/components/content-project-new-content-card.blade.php');

        self::assertStringNotContainsString('wire:model="auditNoteDnaPhrase"', $notes);
        self::assertStringNotContainsString('wire:model="auditNoteDnaWeight"', $notes);
        self::assertStringContainsString('cp-plan-sticky-cta', $card);
        self::assertStringContainsString('data-planner-notes="new-content"', $card);
        self::assertTrue(
            strpos($card, 'data-planner-notes="new-content"') < strpos($card, 'cp-plan-sticky-cta'),
        );
    }

    public function test_local_storage_and_client_dna_semantics_preserved(): void
    {
        $notes = LegacyAddonPath::read('resources/views/components/content-project-audit-notes.blade.php');

        self::assertStringContainsString('seoOps:content-planner:audit-notes:v2:site:', $notes);
        self::assertStringContainsString('loadFromStorage', $notes);
        self::assertStringContainsString('applyAuditNoteItems', $notes);
        self::assertStringContainsString('duplicateDna', $notes);
        self::assertStringContainsString('removeDnaSlot', $notes);
        self::assertStringContainsString('addDna', $notes);
    }

    public function test_batching_contract_still_hides_quantity_input(): void
    {
        $compat = ProjectRoot::addonsPath().DIRECTORY_SEPARATOR.'seo-content-ai-compat';
        $card = (string) file_get_contents($compat.'/resources/views/components/content-project-new-content-card.blade.php');
        $notes = (string) file_get_contents($compat.'/resources/views/components/content-project-audit-notes.blade.php');

        self::assertStringNotContainsString('wire:model="newContentQuantity"', $card);
        self::assertStringNotContainsString('planner_quantity', $card);
        self::assertStringContainsString('planner_ideas_total', $notes);
        self::assertStringContainsString('stickyTotal()', $notes);
    }
}
