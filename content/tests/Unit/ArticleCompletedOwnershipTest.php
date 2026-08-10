<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;

/**
 * article.completed ownership: one production linear WP rule; sample graph disabled;
 * dispatch-publish-request listens to article.publish_requested only.
 */
final class ArticleCompletedOwnershipTest extends TestCase
{
    public function test_seeder_production_sync_rule_enabled_on_article_completed(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/agent/src/Automation/BusinessHook/Seed/AutomationDefaultRulesSeeder.php',
        );

        self::assertStringContainsString("'name' => 'article > wordpress'", $source);
        self::assertStringContainsString('promoteArticleOwnershipRules', $source);
        self::assertMatchesRegularExpression(
            "/sync-article-to-wordpress[\\s\\S]{0,500}'is_enabled'\\s*=>\\s*true/",
            $source,
        );
        self::assertStringContainsString('ArticleCompleted->value', $source);
    }

    public function test_dispatch_publish_request_uses_publish_requested_not_completed(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/agent/src/Automation/BusinessHook/Seed/AutomationDefaultRulesSeeder.php',
        );

        self::assertStringContainsString("'name' => 'article.publish.request > wordpress'", $source);
        self::assertStringContainsString('ArticlePublishRequested->value', $source);
        // Must not seed dispatch-publish-request on article.completed anymore.
        self::assertDoesNotMatchRegularExpression(
            "/code:\\s*'dispatch-publish-request'[\\s\\S]{0,400}ArticleCompleted/",
            $source,
        );
    }

    public function test_graph_sample_forced_disabled(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/agent/src/Automation/BusinessHook/Seed/AutomationDefaultRulesSeeder.php',
        );

        self::assertStringContainsString('sample: article complete pipeline (graph)', $source);
        self::assertStringContainsString('AutomationRuleClassification::Sample', $source);
        self::assertStringContainsString("->where('code', 'article-complete-pipeline-graph')", $source);
    }

    public function test_scheduled_runner_emits_publish_requested_only(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/publishing/src/Services/ScheduledArticlePublishRunner.php',
        );
        self::assertStringContainsString('ArticlePublishRequested', $source);
        self::assertStringNotContainsString('WordPressArticleSyncService', $source);
        self::assertStringNotContainsString('publishForArticle', $source);
    }
}
