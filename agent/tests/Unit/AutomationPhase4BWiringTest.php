<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Migration\ArticleActionOutputNormalizer;
use Omnichannel\Addons\Agent\Automation\Migration\AutomationCallerMigrator;
use Omnichannel\Addons\Agent\Automation\Migration\AutomationMigrationFlags;
use Omnichannel\Addons\Agent\Automation\Migration\AutomationMigrationWriteException;
use Omnichannel\Addons\Agent\Automation\Migration\AutomationParityLogger;
use Omnichannel\Addons\Agent\Automation\Migration\AutomationParitySampleRecorder;
use Omnichannel\Addons\Agent\Automation\Migration\ParitySnapshotNormalizer;
use Omnichannel\Addons\Agent\Automation\Migration\Planners\ArticleContentUpdateParityPlanner;
use Omnichannel\Addons\Agent\Automation\Migration\Planners\ArticleCreateParityPlanner;
use Omnichannel\Addons\Agent\Automation\Migration\Planners\ArticleSeoMetaUpdateParityPlanner;
use Omnichannel\Addons\Agent\Automation\Migration\ProjectArticleContentCallerBridge;
use Omnichannel\Addons\Agent\Automation\Migration\ProjectArticleCreateCallerBridge;
use Omnichannel\Addons\Agent\Automation\Migration\ProjectArticleSeoMetaCallerBridge;
use Omnichannel\Addons\Agent\Automation\Support\ArticleContentConflictGuard;
use Omnichannel\Addons\Agent\Automation\Support\SensitivePayloadRedactor;
use Omnichannel\Addons\ContentProjects\Support\ProjectTaskOriginVariables;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Phase 4B wiring contracts â€” pure unit; khÃ´ng DB.
 */
final class AutomationPhase4BWiringTest extends TestCase
{
    private function migratorStack(): array
    {
        $samples = new AutomationParitySampleRecorder;
        $logger = new AutomationParityLogger(new SensitivePayloadRedactor, $samples);
        $migrator = new AutomationCallerMigrator(new AutomationMigrationFlags, $logger);
        $parity = new ParitySnapshotNormalizer;
        $outputs = new ArticleActionOutputNormalizer;

        return [$migrator, $parity, $outputs];
    }

    public function test_production_callers_reference_group2_bridges(): void
    {
        $create = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/CreateArticlesFromTaskService.php',
        );
        $publish = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/Services/PromptTestPublishService.php',
        );

        self::assertStringContainsString('ProjectArticleCreateCallerBridge', $create);
        self::assertStringContainsString('articleCreateBridge', $create);
        self::assertStringContainsString('ProjectArticleContentCallerBridge', $publish);
        self::assertStringContainsString('ProjectArticleSeoMetaCallerBridge', $publish);
        self::assertStringNotContainsString('ArticleEditorSyncOrchestrator', $publish);
        self::assertStringNotContainsString('WordPressArticleSyncService', $publish);
        self::assertStringNotContainsString('WordPressArticleSyncService', $create);
    }

    public function test_project_task_origin_stamp_and_read(): void
    {
        $stamped = ProjectTaskOriginVariables::stamp(['post_title' => 'x'], 42);
        self::assertSame('42', $stamped[ProjectTaskOriginVariables::KEY]);
        self::assertSame(42, ProjectTaskOriginVariables::read($stamped));
        self::assertNull(ProjectTaskOriginVariables::read(['post_title' => 'x']));
    }

    public function test_create_dedup_contract_maps_existing_origin(): void
    {
        [$migrator, $parity, $outputs] = $this->migratorStack();
        $bridge = new ProjectArticleCreateCallerBridge(
            $migrator,
            $parity,
            new ArticleCreateParityPlanner,
            $outputs,
        );

        Config::set('seo-content-ai.automation_migration.project_article_create', 'legacy');
        $legacyCreates = 0;
        $existing = [
            'article_id' => 55,
            'site_id' => 1,
            'post_type' => 'article',
            'status' => 'draft',
            'deduplicated' => true,
        ];

        $out = $bridge->run(
            [
                'site_id' => 1,
                'post_type' => 'article',
                'origin_type' => 'seo_project_task',
                'origin_id' => 9,
            ],
            static function () use (&$legacyCreates, $existing): array {
                $legacyCreates++;

                return $existing;
            },
            static fn (): ActionResult => ActionResult::success(output: ['article_id' => 1]),
            $existing,
        );

        self::assertSame(1, $legacyCreates);
        self::assertTrue($out['deduplicated']);
        self::assertSame(55, $out['article_id']);
        self::assertFalse($out['changed']);
    }

    public function test_content_conflict_maps_in_planner_output(): void
    {
        $planner = new ArticleContentUpdateParityPlanner(new ArticleContentConflictGuard);
        $guard = new ArticleContentConflictGuard;
        $plan = $planner->plan(
            [
                'article_id' => 3,
                'content' => 'new',
                'title' => 't',
                'expected_content_hash' => 'deadbeef',
            ],
            [
                'article_id' => 3,
                'body' => 'old',
                'title' => 't',
                'status' => 'draft',
            ],
        );

        self::assertTrue($plan['conflict']);
        self::assertNotSame('deadbeef', $guard->contentHash('old'));
    }

    public function test_seo_meta_shadow_does_not_call_action_write(): void
    {
        [$migrator, $parity, $outputs] = $this->migratorStack();
        Config::set('seo-content-ai.automation_migration.project_article_seo_meta_update', 'shadow');
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        $bridge = new ProjectArticleSeoMetaCallerBridge(
            $migrator,
            $parity,
            new ArticleSeoMetaUpdateParityPlanner,
            $outputs,
        );

        $legacy = 0;
        $action = 0;
        $out = $bridge->run(
            ['article_id' => 2, 'meta_description' => 'desc', 'dispatch_scoring' => false],
            [
                'article_id' => 2,
                'meta_description' => '',
                'focus_keyword' => '',
                'slug' => 'a',
                'status' => 'draft',
            ],
            static function () use (&$legacy): array {
                $legacy++;

                return [
                    'article_id' => 2,
                    'meta_description' => 'desc',
                    'seo_analysis_pending' => false,
                    'changed_fields' => ['meta_description'],
                ];
            },
            static function () use (&$action): ActionResult {
                $action++;

                return ActionResult::success(output: [
                    'article_id' => 2,
                    'seo_analysis_pending' => true,
                ]);
            },
        );

        self::assertSame(1, $legacy);
        self::assertSame(0, $action);
        self::assertFalse((bool) ($out['seo_analysis_pending'] ?? true));
    }

    public function test_action_mode_failure_throws_without_legacy_fallback(): void
    {
        [$migrator, $parity, $outputs] = $this->migratorStack();
        $bridge = new ProjectArticleContentCallerBridge(
            $migrator,
            $parity,
            new ArticleContentUpdateParityPlanner(new ArticleContentConflictGuard),
            $outputs,
        );

        Config::set('seo-content-ai.automation_migration.project_article_content_update', 'action');
        $legacy = 0;

        try {
            $bridge->run(
                ['article_id' => 1, 'content' => 'x', 'title' => 't'],
                ['article_id' => 1, 'body' => '', 'title' => 't', 'status' => 'draft'],
                static function () use (&$legacy): array {
                    $legacy++;

                    return ['article_id' => 1];
                },
                static fn (): ActionResult => ActionResult::failure('conflict', 'content conflict'),
            );
            self::fail('Expected AutomationMigrationWriteException');
        } catch (AutomationMigrationWriteException) {
            self::assertSame(0, $legacy);
        }
    }

    public function test_invalid_mode_falls_back_to_legacy(): void
    {
        [$migrator, $parity, $outputs] = $this->migratorStack();
        $bridge = new ProjectArticleCreateCallerBridge(
            $migrator,
            $parity,
            new ArticleCreateParityPlanner,
            $outputs,
        );

        Config::set('seo-content-ai.automation_migration.project_article_create', 'wat');
        $legacy = 0;
        $action = 0;
        $bridge->run(
            ['site_id' => 1],
            static function () use (&$legacy): array {
                $legacy++;

                return ['article_id' => 8, 'site_id' => 1, 'status' => 'draft', 'deduplicated' => false];
            },
            static function () use (&$action): ActionResult {
                $action++;

                return ActionResult::success(output: ['article_id' => 1]);
            },
        );
        self::assertSame(1, $legacy);
        self::assertSame(0, $action);
    }

    public function test_action_result_maps_to_normalized_caller_contract(): void
    {
        [$migrator, $parity, $outputs] = $this->migratorStack();
        $bridge = new ProjectArticleCreateCallerBridge(
            $migrator,
            $parity,
            new ArticleCreateParityPlanner,
            $outputs,
        );

        Config::set('seo-content-ai.automation_migration.project_article_create', 'action');
        $out = $bridge->run(
            ['site_id' => 3, 'post_type' => 'product'],
            static fn (): array => ['article_id' => 1],
            static fn (): ActionResult => ActionResult::success(output: [
                'article_id' => 88,
                'site_id' => 3,
                'post_type' => 'product',
                'status' => 'draft',
                'deduplicated' => false,
            ]),
        );

        self::assertSame(88, $out['article_id']);
        self::assertSame(88, $out['entity_id']);
        self::assertTrue($out['changed']);
        self::assertFalse($out['deduplicated']);
        self::assertArrayHasKey('changed_fields', $out);
    }
}
