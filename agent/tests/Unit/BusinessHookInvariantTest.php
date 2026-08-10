<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Actions\SyncArticleToWordPressHookAction;
use Tests\TestCase;

/**
 * Business Hook wiring invariants â€” static source assertions, no DB.
 */
final class BusinessHookInvariantTest extends TestCase
{
    public function test_production_callers_do_not_reference_wp_sync_services(): void
    {
        $paths = [
            ProjectRoot::addonsPath().'/content-projects/src/Services/CreateArticlesFromTaskService.php',
            ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectWorkflowRunService.php',
            ProjectRoot::addonsPath().'/publishing/src/Services/ArticleScheduleReconcileService.php',
        ];

        foreach ($paths as $path) {
            $source = (string) file_get_contents($path);
            self::assertStringNotContainsString('WordPressArticleSyncService', $source);
            self::assertStringNotContainsString('ArticleWpSyncQueueService', $source);
            self::assertStringNotContainsString('SyncArticleToWordPressFromQueueJob', $source);
        }
    }

    public function test_sync_hook_action_references_wordpress_sync_service(): void
    {
        $hook = (string) file_get_contents(
            ProjectRoot::addonsPath().'/agent/src/Automation/BusinessHook/Actions/SyncArticleToWordPressHookAction.php',
        );

        self::assertStringContainsString('WordPressArticleSyncService', $hook);
        self::assertTrue(class_exists(SyncArticleToWordPressHookAction::class));
    }

    public function test_seeded_production_wp_rules_enabled_sample_and_notify_disabled(): void
    {
        $seeder = (string) file_get_contents(
            ProjectRoot::addonsPath().'/agent/src/Automation/BusinessHook/Seed/AutomationDefaultRulesSeeder.php',
        );

        foreach (['sync-article-to-wordpress', 'dispatch-publish-request'] as $code) {
            self::assertMatchesRegularExpression(
                "/code:\\s*'{$code}'[\\s\\S]{0,800}'is_enabled'\\s*=>\\s*true/",
                $seeder,
                "Expected production rule {$code} to seed enabled.",
            );
        }

        self::assertMatchesRegularExpression(
            "/code:\\s*'notify-workflow-failure'[\\s\\S]{0,800}'is_enabled'\\s*=>\\s*true/",
            $seeder,
            'notify-workflow-failure is production enabled.',
        );
        self::assertStringContainsString('sample: article complete pipeline (graph)', $seeder);
    }
}
