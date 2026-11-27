<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;

/**
 * Global warehouse ownership: source filter ≠ working warehouse ≠ target site.
 */
final class IdeaManagerOwnershipDecoupleContractTest extends TestCase
{
    public function test_normalizer_preserves_optional_provenance_without_inventing(): void
    {
        $with = AuditNoteDnaNormalizer::normalizeNoteItem([
            'source_type' => 'manual_seed',
            'cluster_ref' => 'manual:abc',
            'cluster_name_snapshot' => 'balo giấy Tyvek',
            'seed_text' => 'balo giấy Tyvek',
            'target_dna_count' => 5,
            'target_mode' => 'manual',
            'dna' => [],
            'source_site_id' => 42,
            'source_domain' => 'maybalotuixachgiare.com',
            'bogus_field' => 'drop-me',
        ]);
        self::assertSame(42, $with['source_site_id']);
        self::assertSame('maybalotuixachgiare.com', $with['source_domain']);
        self::assertArrayNotHasKey('bogus_field', $with);

        $without = AuditNoteDnaNormalizer::normalizeNoteItem([
            'source_type' => 'manual_seed',
            'cluster_ref' => 'manual:def',
            'cluster_name_snapshot' => 'foam PE',
            'seed_text' => 'foam PE',
            'target_dna_count' => 5,
            'target_mode' => 'manual',
            'dna' => [],
        ]);
        self::assertArrayNotHasKey('source_site_id', $without);
        self::assertArrayNotHasKey('source_domain', $without);
    }

    public function test_alpine_stamps_cluster_provenance_from_source_filter_not_working_site(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-idea-manager.blade.php');
        self::assertStringContainsString('provenanceSiteId = this.sourceFilterSiteId > 0 ? this.sourceFilterSiteId : this.siteId', $blade);
        self::assertStringContainsString('onSourceFilterChanged(detail)', $blade);
        self::assertStringContainsString('// Sidebar Cluster filter only — never remount warehouse', $blade);
        self::assertStringContainsString('ideaProvenanceDomain(item)', $blade);
    }

    public function test_load_cluster_suggestions_uses_source_filter_site(): void
    {
        $page = dirname(__DIR__, 2).'/src/Filament/Pages/ContentProjectIdeaManager.php';
        $src = (string) file_get_contents($page);
        self::assertStringContainsString(
            '$siteId = (int) ($this->sourceFilterSiteId ?? $this->filterSiteId ?? 0);',
            $src,
        );
    }
}
