<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;

final class TopicClusterPaginationContractTest extends TestCase
{
    public function test_topic_clusters_page_uses_livewire_pagination_state(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusters.php');

        self::assertStringContainsString('use Livewire\\WithPagination;', $page);
        self::assertStringContainsString('use WithPagination;', $page);
        self::assertStringContainsString('page: $this->getPage()', $page);
        self::assertStringContainsString('public function updatedCoverageFilter(): void', $page);
        self::assertStringContainsString('public function updatedClusterSort(): void', $page);
        self::assertStringContainsString('public function updatedClusterProjection(): void', $page);
        self::assertStringContainsString('$this->resetPage();', $page);

        $applyPos = strpos($page, 'function applyClusterSearch');
        $applyResetPos = strpos($page, '$this->resetPage();', $applyPos !== false ? $applyPos : 0);
        self::assertNotFalse($applyPos);
        self::assertNotFalse($applyResetPos);
        self::assertLessThan(400, $applyResetPos - $applyPos);

        $sitePos = strpos($page, 'function onKeywordWorkspaceSiteFilterChanged');
        $siteResetPos = strpos($page, '$this->resetPage();', $sitePos !== false ? $sitePos : 0);
        self::assertNotFalse($sitePos);
        self::assertNotFalse($siteResetPos);
        self::assertLessThan(250, $siteResetPos - $sitePos);
    }

    public function test_paginate_clusters_accepts_explicit_page_argument(): void
    {
        $query = (string) file_get_contents(dirname(__DIR__, 2).'/src/Services/KeywordIntelligence/KeywordClusterQuery.php');

        self::assertStringContainsString('?int $page = null', $query);
        self::assertStringContainsString('$resolvedPage = max(1, $page ?? (int) request()->integer(\'page\', 1));', $query);
        self::assertStringNotContainsString("\$page = max(1, (int) request()->integer('page', 1));", $query);
    }
}
