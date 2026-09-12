<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * V2 structure: tabs, single-social topic, manager gate, website share delay.
 */
final class SeedingV2StructureContractTest extends TestCase
{
    private function addonRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_workspace_has_three_main_tabs_and_manager_gate(): void
    {
        $workspace = (string) file_get_contents($this->addonRoot().'/resources/js/seeding/SeedingWorkspace.jsx');
        self::assertStringContainsString('Feed Seeding', $workspace);
        self::assertStringContainsString('Bài từ Website', $workspace);
        self::assertStringContainsString('Quản lý / Tổng kết', $workspace);
        self::assertStringContainsString('mainTab === \'manage\' && manager', $workspace);
        self::assertStringContainsString('ManagerPanel', $workspace);
        self::assertStringContainsString('WebsiteShareFeed', $workspace);
    }

    public function test_feed_is_single_column(): void
    {
        $css = (string) file_get_contents($this->addonRoot().'/resources/css/seeding-workspace.css');
        self::assertMatchesRegularExpression(
            '/\.seeding-ws__feed-grid\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\)/s',
            $css
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.seeding-ws__feed-grid\s*\{[^}]*repeat\(\s*[23]/s',
            $css
        );
        self::assertStringNotContainsString('@container seeding-feed', $css);
    }

    public function test_access_manager_inherits_seo_role(): void
    {
        $access = (string) file_get_contents($this->addonRoot().'/src/Support/SeedingAccess.php');
        self::assertStringContainsString('function isManager', $access);
        self::assertStringContainsString('SEO_ROLE_MANAGER', $access);
        self::assertStringContainsString('function canManageTopics', $access);
    }

    public function test_share_requires_social_and_can_expand(): void
    {
        $share = (string) file_get_contents($this->addonRoot().'/src/Http/Controllers/SeedingShareTopicController.php');
        $service = (string) file_get_contents($this->addonRoot().'/src/Services/SeedingSharedTopicService.php');
        self::assertStringContainsString('assertCanManage', $share);
        self::assertStringContainsString('social_targets', $share);
        self::assertStringContainsString('Social là bắt buộc', $share);
        self::assertStringContainsString('normalizeExecutions', $service);
        self::assertStringContainsString('one execution topic = one social', $service);
    }

    public function test_report_is_atomic_against_global_target(): void
    {
        $report = (string) file_get_contents($this->addonRoot().'/src/Services/SeedingReportService.php');
        self::assertStringContainsString('lockForUpdate', $report);
        self::assertStringContainsString('completed_comments', $report);
        self::assertStringContainsString('Chủ đề đã đủ quota', $report);
        self::assertStringContainsString('topic_done', $report);
    }

    public function test_website_share_delay_and_event_decoupling(): void
    {
        $service = (string) file_get_contents($this->addonRoot().'/src/Services/WebsiteShareJobService.php');
        $job = (string) file_get_contents($this->addonRoot().'/src/Jobs/CheckArticleForWebsiteShareJob.php');
        $listener = (string) file_get_contents($this->addonRoot().'/src/Listeners/ArticleIndexStatusChangedListener.php');
        $coreEvent = (string) file_get_contents(
            dirname($this->addonRoot(), 2).'/omnichannel-client/app/Core/Event/ArticleIndexStatusChanged.php'
        );

        self::assertStringContainsString('DELAY_MINUTES = 10', $service);
        self::assertStringContainsString('CheckArticleForWebsiteShareJob::dispatch', $service);
        self::assertStringContainsString('promoteIfStillIndexed', $job);
        self::assertStringContainsString('ArticleIndexStatusChanged', $listener);
        self::assertStringContainsString('content.article_index_status_changed', $coreEvent);
        self::assertFileExists($this->addonRoot().'/database/migrations/2026_09_12_100100_create_website_share_jobs_tables.php');
    }

    public function test_composer_supports_multi_social_rows(): void
    {
        $composer = (string) file_get_contents($this->addonRoot().'/resources/js/seeding/components/TopicComposer.jsx');
        self::assertStringContainsString('social_targets', $composer);
        self::assertStringContainsString('Thêm social', $composer);
        self::assertStringContainsString('Max comments', $composer);
    }
}
