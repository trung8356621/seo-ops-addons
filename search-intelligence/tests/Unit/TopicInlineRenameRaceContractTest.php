<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;

/**
 * Contract: Topic inline rename is item-level — no full list refresh/race.
 */
final class TopicInlineRenameRaceContractTest extends TestCase
{
    public function test_index_rename_is_renderless_and_returns_row_payload(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusters.php');

        $maskPos = strpos($page, 'function saveMcpGroupMaskFromIndex');
        $canonicalPos = strpos($page, 'function saveClusterCanonicalFromIndex');
        self::assertNotFalse($maskPos);
        self::assertNotFalse($canonicalPos);

        $maskChunk = substr($page, max(0, $maskPos - 120), 400);
        $canonicalChunk = substr($page, max(0, $canonicalPos - 200), 900);

        self::assertStringContainsString('#[Renderless]', $maskChunk);
        self::assertStringContainsString('#[Renderless]', $canonicalChunk);

        $canonicalBody = substr($page, $canonicalPos, 2500);
        self::assertStringContainsString("'cluster_key' => \$clusterKey", $canonicalBody);
        self::assertStringContainsString("'keyword_count'", $canonicalBody);
        self::assertStringContainsString("'article_count'", $canonicalBody);
        self::assertStringContainsString("'internal_link_count'", $canonicalBody);
        self::assertStringContainsString("'coverage'", $canonicalBody);
        self::assertStringNotContainsString('refreshClusterSummaryCounters()', $canonicalBody);

        $maskBody = substr($page, $maskPos, 1200);
        self::assertStringNotContainsString('refreshClusterSummaryCounters()', $maskBody);
    }

    public function test_frontend_patches_row_without_full_refresh(): void
    {
        $index = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php',
        ));

        self::assertStringContainsString('renameSeq', $index);
        self::assertStringContainsString('applyRowPatch(result)', $index);
        self::assertStringContainsString('if (seq !== this.renameSeq)', $index);
        self::assertStringContainsString('this.value = next', $index);
        self::assertStringContainsString('this.original = next', $index);
        self::assertStringContainsString('previousTitle', $index);
        self::assertStringNotContainsString('await $wire.$refresh()', $index);
        self::assertStringNotContainsString('$wire.$refresh()', $index);
    }
}
