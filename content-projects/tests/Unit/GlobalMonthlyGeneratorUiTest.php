<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\GlobalMonthlyGeneratorPage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class GlobalMonthlyGeneratorUiTest extends TestCase
{
    public function test_page_header_title_subheading_and_tabs_contract(): void
    {
        $page = new GlobalMonthlyGeneratorPage();
        $page->planningMonth = '2026-10';
        $page->preflight = [
            'projects' => [
                ['project_id' => 101, 'name' => 'Project Alpha', 'eligible_count' => 15],
                ['project_id' => 102, 'name' => 'Project Beta', 'eligible_count' => 0],
            ],
            'eligible_count' => 15,
            'legacy_active_runs' => [],
            'can_start' => true,
        ];

        self::assertSame('Generate tháng', $page->getTitle());
        self::assertSame('Generate tháng', (string) $page->getHeading());
        self::assertSame('Tháng 10/2026 · 2 Project', (string) $page->getSubheading());

        $ref = new ReflectionClass($page);
        self::assertTrue($ref->hasMethod('getHeaderActions'));
        self::assertTrue($ref->hasMethod('setActiveTab'));
    }

    public function test_blade_structure_matches_content_project_progress_design(): void
    {
        $blade = (string) realpath(
            dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/filament/pages/global-monthly-generator.blade.php',
        );
        self::assertFileExists($blade);
        $src = (string) file_get_contents($blade);

        // Header and Shell
        self::assertStringContainsString('data-gm-progress-page', $src);
        self::assertStringContainsString('data-gm-progress-chrome', $src);
        self::assertStringContainsString('data-gm-card', $src);

        // Setup section
        self::assertStringContainsString("activeTab === 'setup'", $src);
        self::assertStringContainsString('wire:model.live="planningMonth"', $src);
        self::assertStringContainsString('Auto Cost', $src);
        self::assertStringContainsString('Free → Paid → Auto Rerun', $src);
        self::assertStringNotContainsString('wire:model="aiCostPolicy"', $src);
        self::assertStringContainsString('wire:click="startBatch"', $src);
        self::assertStringContainsString('cursor-not-allowed', $src);
        self::assertStringContainsString('data-gm-start-block-reason', $src);
        self::assertStringContainsString('Generate tháng', $src);
        self::assertStringContainsString('gm-setup', $src);
        self::assertStringContainsString('Bài chưa Generate', $src);
        self::assertStringContainsString('Bài lỗi', $src);
        self::assertStringContainsString('Trạng thái', $src);
        self::assertTrue(
            strpos($src, 'Bài chưa Generate') < strpos($src, 'Bài lỗi')
            && strpos($src, 'Bài lỗi') < strpos($src, 'Trạng thái'),
            'Column "Bài lỗi" must be after "Bài chưa Generate" and before "Trạng thái".'
        );
        self::assertStringContainsString('bài lỗi', $src);
        self::assertStringContainsString('gm-setup-failed', $src);
        self::assertStringContainsString("project['owner_name']", $src);

        // Progress section
        self::assertStringContainsString("activeTab === 'progress'", $src);
        self::assertStringContainsString('gm-progress-playlist', $src);
        self::assertStringContainsString('data-gm-playlist-scroll', $src);
        self::assertStringContainsString('nowPlaying', $src);
        self::assertStringContainsString("nowPlaying['owner_name']", $src);
        self::assertStringContainsString("row['owner_name']", $src);
        self::assertStringContainsString('gm-progress-bar-track', $src);
        self::assertStringContainsString('gm-progress-bar-fill', $src);
        self::assertStringContainsString('wire:poll.5s="refreshProgress"', $src);
        self::assertStringContainsString('Chưa có đợt Generate nào trong tháng này.', $src);
        self::assertStringContainsString('Emergency Stop', $src);

        // JavaScript single-timer elapsed updates
        self::assertStringContainsString('data-gm-running-start-ts', $src);
        self::assertStringContainsString('data-gm-now-playing-elapsed', $src);
        self::assertStringContainsString('data-gm-running-row-elapsed', $src);
        self::assertStringContainsString('tickTimer()', $src);
        self::assertStringContainsString('startTimer()', $src);
        self::assertStringContainsString('stopTimer()', $src);

        // History section
        self::assertStringContainsString("activeTab === 'history'", $src);
        self::assertStringContainsString('gm-history', $src);
        self::assertStringContainsString('wire:click="openBatch(', $src);
        self::assertStringContainsString('Xem tiến trình →', $src);
    }

    public function test_tabs_partial_reuses_approved_tab_pattern(): void
    {
        $tabsPath = (string) realpath(
            dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/filament/pages/partials/global-monthly-tabs.blade.php',
        );
        self::assertFileExists($tabsPath);
        $src = (string) file_get_contents($tabsPath);

        self::assertStringContainsString('Thiết lập', $src);
        self::assertStringContainsString('Tiến trình', $src);
        self::assertStringContainsString('Lịch sử', $src);
        self::assertStringContainsString("wire:click=\"setActiveTab('setup')\"", $src);
        self::assertStringContainsString("wire:click=\"setActiveTab('progress')\"", $src);
        self::assertStringContainsString("wire:click=\"setActiveTab('history')\"", $src);
        self::assertStringContainsString('bg-primary-600 text-white shadow-sm', $src);
    }
}
