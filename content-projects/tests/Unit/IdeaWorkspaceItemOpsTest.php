<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaWorkspaceItemOps;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaWorkspacePersistenceService;
use PHPUnit\Framework\TestCase;

final class IdeaWorkspaceItemOpsTest extends TestCase
{
    public function test_bulk_preview_trims_skips_blank_dedupes_and_caps(): void
    {
        $existing = [];
        $made = IdeaWorkspaceItemOps::makeManualSeed('đã có', $existing);
        self::assertNotNull($made['item']);
        $existing[] = $made['item'];

        $text = "balo siêu nhẹ cho bé mầm non\n\n  balo vải cao su bọt biển  \nđã có\nbalo siêu nhẹ cho bé mầm non\n";
        $preview = IdeaWorkspaceItemOps::previewBulkLines($text, $existing);

        self::assertCount(2, $preview['valid']);
        self::assertGreaterThanOrEqual(2, count($preview['duplicates']));
        self::assertFalse($preview['truncated']);
    }

    public function test_bulk_preview_truncates_over_max_lines(): void
    {
        $lines = [];
        for ($i = 0; $i < IdeaWorkspaceItemOps::MAX_BULK_LINES + 5; $i++) {
            $lines[] = 'idea line '.$i;
        }
        $preview = IdeaWorkspaceItemOps::previewBulkLines(implode("\n", $lines), []);
        self::assertTrue($preview['truncated']);
        self::assertCount(IdeaWorkspaceItemOps::MAX_BULK_LINES, $preview['valid']);
    }

    public function test_cluster_suggestion_duplicate_by_ref(): void
    {
        $existing = [[
            'source_type' => 'cluster',
            'cluster_ref' => 'topic:9',
            'cluster_name_snapshot' => 'Balo',
            'seed_text' => null,
            'mcp_share_snapshot' => 1.0,
            'target_dna_count' => 5,
            'target_mode' => 'manual',
            'dna' => [],
        ]];
        $result = IdeaWorkspaceItemOps::makeFromClusterSuggestion([
            'cluster_ref' => 'topic:9',
            'cluster_name' => 'Balo',
            'mcp_share' => 2,
            'cluster_dna' => [],
        ], $existing);
        self::assertTrue($result['duplicate']);
        self::assertNull($result['item']);
    }

    public function test_audit_import_preview_site_scoped_and_deduped(): void
    {
        $existing = [];
        $seed = IdeaWorkspaceItemOps::makeManualSeed('local idea', $existing);
        $existing[] = $seed['item'];

        $parsed = [
            'version' => 5,
            'site_id' => 12,
            'items' => [
                [
                    'source_type' => 'manual_seed',
                    'cluster_ref' => 'manual:abc',
                    'cluster_name_snapshot' => 'local idea',
                    'seed_text' => 'local idea',
                    'target_dna_count' => 5,
                    'dna' => [],
                ],
                [
                    'source_type' => 'cluster',
                    'cluster_ref' => 'topic:3',
                    'cluster_name_snapshot' => 'New cluster idea',
                    'target_dna_count' => 5,
                    'dna' => [],
                ],
            ],
        ];

        $wrongSite = IdeaWorkspaceItemOps::previewAuditImport($parsed, 99, $existing);
        self::assertSame([], $wrongSite['importable']);

        $ok = IdeaWorkspaceItemOps::previewAuditImport($parsed, 12, $existing);
        self::assertCount(1, $ok['importable']);
        self::assertCount(1, $ok['duplicates']);
        self::assertSame('topic:3', $ok['importable'][0]['cluster_ref']);
    }

    public function test_content_hash_stable_for_same_normalized_items(): void
    {
        $a = IdeaWorkspaceItemOps::makeManualSeed('Hash me', []);
        $items = [$a['item']];
        $h1 = IdeaWorkspacePersistenceService::contentHash($items);
        $h2 = IdeaWorkspacePersistenceService::contentHash($items);
        self::assertSame($h1, $h2);
        self::assertSame(64, strlen($h1));
    }

    public function test_storage_key_prefixes_are_distinct(): void
    {
        self::assertStringStartsWith('seoOps:idea-manager:draft:v1:site:', IdeaWorkspaceItemOps::IM_DRAFT_PREFIX);
        self::assertStringStartsWith('seoOps:content-planner:audit-notes:v2:site:', IdeaWorkspaceItemOps::AUDIT_STORAGE_PREFIX);
        self::assertNotSame(IdeaWorkspaceItemOps::IM_DRAFT_PREFIX, IdeaWorkspaceItemOps::AUDIT_STORAGE_PREFIX);
    }
}
