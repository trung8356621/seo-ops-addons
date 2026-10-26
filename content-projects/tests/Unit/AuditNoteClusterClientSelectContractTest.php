<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithAuditNotes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteClusterSuggestionQuery;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Cluster checkbox is client-side Alpine; page rows preload DNA for selectCluster().
 */
final class AuditNoteClusterClientSelectContractTest extends TestCase
{
    public function test_paginate_attaches_cluster_dna_for_current_page_only(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(AuditNoteClusterSuggestionQuery::class))->getFileName(),
        );

        self::assertStringContainsString('buildSuggestionItemsWithDnaIndex', $src);
        self::assertStringContainsString('attachPageClusterDna', $src);
        self::assertStringContainsString("forSite(\$siteId, true)", $src);
        self::assertStringContainsString("\$row['cluster_dna']", $src);
        self::assertStringContainsString('array_slice($topic->dna, 0, self::DNA_LIMIT)', $src);
        // List rows stay DNA-free until page attach (payload stays page-scoped).
        self::assertStringContainsString('mapTopicToSuggestion($topic, $plannedByRef, false)', $src);
    }

    public function test_blade_select_is_alpine_only_and_generate_uses_snapshot(): void
    {
        $notes = LegacyAddonPath::read('resources/views/components/content-project-audit-notes.blade.php');
        $card = LegacyAddonPath::read('resources/views/components/content-project-new-content-card.blade.php');

        self::assertStringContainsString('selectCluster(@js($clusterSnapshot))', $notes);
        self::assertStringContainsString("'cluster_dna'", $notes);
        self::assertStringNotContainsString('wire:click.prevent="toggleAuditNoteCluster', $notes);
        self::assertStringNotContainsString('$wire.applyAuditNoteItems', $notes);
        self::assertStringNotContainsString('$wire.removeAuditNoteItem', $notes);
        self::assertStringContainsString('generateNewContentSuggestions(items)', $card);

        $concern = (string) file_get_contents(
            (string) (new ReflectionClass(InteractsWithAuditNotes::class))->getFileName(),
        );
        // Keep server APIs for other consumers; UI no longer calls them per click.
        self::assertStringContainsString('function toggleAuditNoteCluster', $concern);
        self::assertStringContainsString('function applyAuditNoteItems', $concern);
        self::assertStringContainsString('function removeAuditNoteItem', $concern);
    }

    public function test_merge_server_prefer_local_does_not_prune_client_selections(): void
    {
        $notes = LegacyAddonPath::read('resources/views/components/content-project-audit-notes.blade.php');

        self::assertStringContainsString('preferLocal must NOT drop', $notes);
        self::assertStringContainsString('if (!preferLocal) {', $notes);
        self::assertStringNotContainsString('else if (list.length > 0 && serverRefs.size > 0)', $notes);
        self::assertStringContainsString('_removedRefs', $notes);
        self::assertStringContainsString('Cross-site: always reload', $notes);
    }
}
