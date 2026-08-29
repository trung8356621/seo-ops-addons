<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;

final class ContentProjectListLoadingUxTest extends TestCase
{
    public function test_project_list_and_ops_use_article_style_overlay_not_hiding_table(): void
    {
        $list = LegacyAddonPath::read('resources/views/filament/resources/seo-project-resource/pages/list-seo-projects.blade.php');
        $ops = LegacyAddonPath::read('resources/views/filament/resources/seo-project-resource/pages/view-seo-project-operations.blade.php');
        $archive = LegacyAddonPath::read('resources/views/filament/resources/seo-project-resource/pages/content-project-archive.blade.php');
        $draft = LegacyAddonPath::read('resources/views/components/content-project-draft-items.blade.php');
        $suggestions = LegacyAddonPath::read('resources/views/components/content-project-seo-audit-planner.blade.php');
        $queue = LegacyAddonPath::read('resources/views/filament/resources/seo-project-resource/pages/content-project-publishing-queue.blade.php');

        self::assertStringContainsString('list-table-loading-shell', $list);
        self::assertStringContainsString('planningMonth', $list);
        self::assertStringContainsString('preset="filament-table"', $list);

        self::assertStringContainsString('list-table-loading-shell', $ops);
        self::assertStringContainsString('applySummaryFilter', $ops);
        self::assertStringContainsString('gotoPage', LegacyAddonPath::read('resources/views/components/list-table-loading-shell.blade.php'));
        self::assertStringNotContainsString('wire:loading.remove.delay.shortest', $ops);
        self::assertStringNotContainsString('h-14 animate-pulse', $ops);
        self::assertStringNotContainsString('lazyRefreshOps', $this->opsShellTargets($ops));
        self::assertStringNotContainsString('generatePostImages', $this->opsShellTargets($ops));
        self::assertStringNotContainsString('archiveOne', $this->opsShellTargets($ops));

        self::assertStringContainsString('list-table-loading-shell', $archive);
        self::assertStringContainsString('updatedSearch', $archive);

        self::assertStringContainsString('list-table-loading-shell', $draft);
        self::assertStringContainsString('setDraftReviewFilter', $draft);
        self::assertStringNotContainsString('wire:target="setDraftReviewFilter,setDraftTypeFilter,archiveOne,skipSeoAuditOne"', $draft);

        self::assertStringContainsString('list-table-loading-shell', $suggestions);
        self::assertStringContainsString('suggestionSearch', $suggestions);

        self::assertStringContainsString('list-table-loading-shell', $queue);
        self::assertStringContainsString('statusFilter', $queue);
        self::assertStringNotContainsString('retryOne', $this->firstShellTargets($queue));
    }

    private function opsShellTargets(string $ops): string
    {
        if (! preg_match('/list-table-loading-shell[^>]*>/s', $ops, $match)) {
            return '';
        }

        return $match[0];
    }

    private function firstShellTargets(string $blade): string
    {
        if (! preg_match('/list-table-loading-shell[^>]*>/s', $blade, $match)) {
            return '';
        }

        return $match[0];
    }
}
