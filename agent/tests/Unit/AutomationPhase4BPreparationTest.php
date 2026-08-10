<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Enums\MigrationMode;
use Omnichannel\Addons\Agent\Automation\Migration\ArticleActionOutputNormalizer;
use Omnichannel\Addons\Agent\Automation\Migration\AutomationCallerMigrator;
use Omnichannel\Addons\Agent\Automation\Migration\AutomationMigrationFlags;
use Omnichannel\Addons\Agent\Automation\Migration\AutomationParityLogger;
use Omnichannel\Addons\Agent\Automation\Migration\AutomationParitySampleRecorder;
use Omnichannel\Addons\Agent\Automation\Migration\ParitySnapshotNormalizer;
use Omnichannel\Addons\Agent\Automation\Migration\Planners\ArticleContentUpdateParityPlanner;
use Omnichannel\Addons\Agent\Automation\Migration\Planners\ArticleCreateParityPlanner;
use Omnichannel\Addons\Agent\Automation\Migration\Planners\ArticleSeoMetaUpdateParityPlanner;
use Omnichannel\Addons\Agent\Automation\Migration\ProjectArticleContentCallerBridge;
use Omnichannel\Addons\Agent\Automation\Migration\ProjectArticleCreateCallerBridge;
use Omnichannel\Addons\Agent\Automation\Migration\ProjectArticleSeoMetaCallerBridge;
use Omnichannel\Addons\Agent\Automation\Support\ArticleContentConcurrencyLimitations;
use Omnichannel\Addons\Agent\Automation\Support\ArticleContentConflictGuard;
use Omnichannel\Addons\Agent\Automation\Support\SensitivePayloadRedactor;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Phase 4B preparation — pure unit / mock; không DB.
 */
final class AutomationPhase4BPreparationTest extends TestCase
{
    private function migratorStack(): array
    {
        $samples = new AutomationParitySampleRecorder;
        $logger = new AutomationParityLogger(new SensitivePayloadRedactor, $samples);
        $migrator = new AutomationCallerMigrator(new AutomationMigrationFlags, $logger);
        $parity = new ParitySnapshotNormalizer;
        $outputs = new ArticleActionOutputNormalizer;

        return [$migrator, $parity, $outputs, $samples];
    }

    public function test_create_planner_dedup_and_would_create(): void
    {
        $planner = new ArticleCreateParityPlanner;
        $dedup = $planner->plan(
            ['site_id' => 1, 'post_type' => 'article', 'origin_type' => 'seo_project_task', 'origin_id' => 9],
            ['article_id' => 55, 'site_id' => 1, 'status' => 'draft', 'post_type' => 'article'],
        );
        self::assertTrue($dedup['deduplicated']);
        self::assertSame(55, $dedup['article_id']);

        $create = $planner->plan(['site_id' => 2], null);
        self::assertFalse($create['deduplicated']);
        self::assertTrue($create['would_create']);
        self::assertNull($create['article_id']);
    }

    public function test_content_planner_noop_conflict_and_change(): void
    {
        $planner = new ArticleContentUpdateParityPlanner(new ArticleContentConflictGuard);
        $state = [
            'article_id' => 3,
            'status' => 'draft',
            'body' => 'hello',
            'title' => 'T',
            'updated_at' => '2026-07-01T00:00:00+00:00',
        ];

        $noop = $planner->plan(['article_id' => 3, 'content' => 'hello', 'title' => 'T'], $state);
        self::assertTrue($noop['noop']);
        self::assertFalse($noop['would_persist']);

        $hash = (new ArticleContentConflictGuard)->contentHash('hello');
        $conflict = $planner->plan([
            'article_id' => 3,
            'content' => 'new',
            'expected_content_hash' => 'deadbeef',
        ], $state);
        self::assertTrue($conflict['conflict']);

        $change = $planner->plan(['article_id' => 3, 'content' => 'world', 'title' => 'T'], $state);
        self::assertFalse($change['noop']);
        self::assertContains('content', $change['changed_fields']);
        self::assertSame($hash, $noop['content_hash']);
    }

    public function test_seo_meta_planner_fields_and_noop(): void
    {
        $planner = new ArticleSeoMetaUpdateParityPlanner;
        $state = [
            'article_id' => 4,
            'slug' => 'old-slug',
            'focus_keyword' => 'kw',
            'meta_description' => 'desc',
            'status' => 'draft',
        ];

        $noop = $planner->plan([
            'article_id' => 4,
            'focus_keyword' => 'kw',
            'meta_description' => 'desc',
            'slug' => 'old-slug',
        ], $state);
        self::assertTrue($noop['noop']);
        self::assertFalse($noop['would_dispatch_scoring']);

        $change = $planner->plan([
            'article_id' => 4,
            'slug' => 'New Slug!',
            'meta_description' => 'desc',
        ], $state);
        self::assertContains('slug', $change['changed_fields']);
        self::assertTrue($change['would_mark_sync_pending_on_slug']);
        self::assertSame('new-slug', $change['slug']);
    }

    public function test_output_normalizer_stable_shape(): void
    {
        $n = new ArticleActionOutputNormalizer;
        $create = $n->create(['article_id' => 1, 'site_id' => 2, 'status' => 'draft', 'deduplicated' => false]);
        self::assertSame(1, $create['entity_id']);
        self::assertTrue($create['changed']);
        self::assertSame(['article'], $create['changed_fields']);

        $content = $n->content(['article_id' => 1, 'status' => 'draft', 'noop' => true, 'content_hash' => 'abc']);
        self::assertTrue($content['deduplicated']);
        self::assertFalse($content['changed']);
    }

    public function test_parity_snapshot_article_helpers(): void
    {
        $parity = new ParitySnapshotNormalizer;
        $snap = $parity->articleCreate([
            'article_id' => 9,
            'site_id' => 1,
            'status' => 'draft',
            'deduplicated' => true,
        ]);
        self::assertTrue($snap['noop']);
        self::assertSame(9, $snap['ids']['article_id']);
    }

    public function test_create_bridge_legacy_shadow_action_modes(): void
    {
        [$migrator, $parity, $outputs] = $this->migratorStack();
        $bridge = new ProjectArticleCreateCallerBridge(
            $migrator,
            $parity,
            new ArticleCreateParityPlanner,
            $outputs,
        );

        Config::set('seo-content-ai.automation_migration.project_article_create', 'legacy');
        $legacyCalls = 0;
        $actionCalls = 0;
        $out = $bridge->run(
            ['site_id' => 1],
            static function () use (&$legacyCalls): array {
                $legacyCalls++;

                return ['article_id' => 10, 'site_id' => 1, 'status' => 'draft', 'deduplicated' => false];
            },
            static function () use (&$actionCalls): ActionResult {
                $actionCalls++;

                return ActionResult::success(output: ['article_id' => 99]);
            },
        );
        self::assertSame(1, $legacyCalls);
        self::assertSame(0, $actionCalls);
        self::assertSame(10, $out['article_id']);

        Config::set('seo-content-ai.automation_migration.project_article_create', 'shadow');
        Log::shouldReceive('info')->once();
        $legacyCalls = 0;
        $actionCalls = 0;
        $bridge->run(
            ['site_id' => 1],
            static function () use (&$legacyCalls): array {
                $legacyCalls++;

                return ['article_id' => 10, 'site_id' => 1, 'status' => 'draft', 'post_type' => 'article', 'deduplicated' => false];
            },
            static function () use (&$actionCalls): ActionResult {
                $actionCalls++;

                return ActionResult::success();
            },
            null,
        );
        self::assertSame(1, $legacyCalls);
        self::assertSame(0, $actionCalls);

        Config::set('seo-content-ai.automation_migration.project_article_create', 'action');
        $legacyCalls = 0;
        $actionCalls = 0;
        $bridge->run(
            ['site_id' => 1],
            static function () use (&$legacyCalls): array {
                $legacyCalls++;

                return ['article_id' => 1];
            },
            static function () use (&$actionCalls): ActionResult {
                $actionCalls++;

                return ActionResult::success(output: [
                    'article_id' => 77,
                    'site_id' => 1,
                    'status' => 'draft',
                    'deduplicated' => false,
                ]);
            },
        );
        self::assertSame(0, $legacyCalls);
        self::assertSame(1, $actionCalls);
    }

    public function test_content_and_seo_meta_bridges_shadow_no_action_write(): void
    {
        [$migrator, $parity, $outputs] = $this->migratorStack();

        Config::set('seo-content-ai.automation_migration.project_article_content_update', 'shadow');
        Log::shouldReceive('info')->twice();

        $contentBridge = new ProjectArticleContentCallerBridge(
            $migrator,
            $parity,
            new ArticleContentUpdateParityPlanner(new ArticleContentConflictGuard),
            $outputs,
        );
        $actionCalls = 0;
        $state = ['article_id' => 5, 'body' => 'x', 'title' => 't', 'status' => 'draft'];
        $contentBridge->run(
            ['article_id' => 5, 'content' => 'x', 'title' => 't'],
            $state,
            static fn (): array => ['article_id' => 5, 'status' => 'draft', 'noop' => true, 'content_hash' => (new ArticleContentConflictGuard)->contentHash('x')],
            static function () use (&$actionCalls): ActionResult {
                $actionCalls++;

                return ActionResult::success();
            },
        );
        self::assertSame(0, $actionCalls);

        Config::set('seo-content-ai.automation_migration.project_article_seo_meta_update', 'shadow');
        $seoBridge = new ProjectArticleSeoMetaCallerBridge(
            $migrator,
            $parity,
            new ArticleSeoMetaUpdateParityPlanner,
            $outputs,
        );
        $metaState = ['article_id' => 5, 'slug' => 'a', 'focus_keyword' => 'k', 'meta_description' => 'd'];
        $seoBridge->run(
            ['article_id' => 5, 'slug' => 'a', 'focus_keyword' => 'k', 'meta_description' => 'd'],
            $metaState,
            static fn (): array => [
                'article_id' => 5,
                'slug' => 'a',
                'focus_keyword' => 'k',
                'meta_description' => 'd',
                'noop' => true,
                'changed_fields' => [],
            ],
            static function () use (&$actionCalls): ActionResult {
                $actionCalls++;

                return ActionResult::success();
            },
        );
        self::assertSame(0, $actionCalls);
    }

    public function test_concurrency_limitations_catalog(): void
    {
        $items = ArticleContentConcurrencyLimitations::catalog();
        self::assertNotEmpty($items);
        $ids = array_column($items, 'id');
        self::assertContains('no_revision_column', $ids);
        self::assertContains('hash_trim_only', $ids);
        self::assertContains('updated_at_second_precision', $ids);
    }

    public function test_group2_flags_default_legacy(): void
    {
        Config::set('seo-content-ai.automation_migration.project_article_create', 'legacy');
        Config::set('seo-content-ai.automation_migration.project_article_content_update', 'legacy');
        Config::set('seo-content-ai.automation_migration.project_article_seo_meta_update', 'legacy');
        $flags = new AutomationMigrationFlags;
        self::assertFalse($flags->isLegacy(AutomationMigrationFlags::PROJECT_ARTICLE_CREATE));
        self::assertFalse($flags->isLegacy(AutomationMigrationFlags::PROJECT_ARTICLE_CONTENT_UPDATE));
        self::assertFalse($flags->isLegacy(AutomationMigrationFlags::PROJECT_ARTICLE_SEO_META_UPDATE));
        self::assertSame(MigrationMode::Action, $flags->mode(AutomationMigrationFlags::PROJECT_ARTICLE_CREATE));
    }

    public function test_planner_does_not_accept_eloquent_in_output_normalizer(): void
    {
        $n = new ArticleActionOutputNormalizer;
        $out = $n->create(['article_id' => 1, 'site_id' => 1, 'status' => 'draft']);
        foreach ($out as $value) {
            self::assertFalse(is_object($value));
        }
    }
}
