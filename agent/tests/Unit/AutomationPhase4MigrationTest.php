<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Enums\MigrationMode;
use Omnichannel\Addons\Agent\Automation\Migration\AutomationCallerMigrator;
use Omnichannel\Addons\Agent\Automation\Migration\AutomationMigrationFlags;
use Omnichannel\Addons\Agent\Automation\Migration\AutomationParityLogger;
use Omnichannel\Addons\Agent\Automation\Migration\AutomationParitySampleRecorder;
use Omnichannel\Addons\Agent\Automation\Support\ArticleContentConflictGuard;
use Omnichannel\Addons\Agent\Automation\Support\ArticleCreateOriginResolver;
use Omnichannel\Addons\Agent\Automation\Support\SensitivePayloadRedactor;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class AutomationPhase4MigrationTest extends TestCase
{
    private function migrator(): AutomationCallerMigrator
    {
        return new AutomationCallerMigrator(
            new AutomationMigrationFlags,
            new AutomationParityLogger(new SensitivePayloadRedactor, new AutomationParitySampleRecorder),
        );
    }
    public function test_migration_mode_from_config(): void
    {
        // Full cutover: mọi config value → Action (trừ emergency env).
        self::assertSame(MigrationMode::Action, MigrationMode::fromConfig('legacy'));
        self::assertSame(MigrationMode::Action, MigrationMode::fromConfig('shadow'));
        self::assertSame(MigrationMode::Action, MigrationMode::fromConfig('action'));
        self::assertSame(MigrationMode::Action, MigrationMode::fromConfig('garbage'));
        self::assertTrue(MigrationMode::Shadow->writesViaLegacy());
        self::assertFalse(MigrationMode::Shadow->writesViaAction());
        self::assertTrue(MigrationMode::Action->writesViaAction());
        self::assertTrue(MigrationMode::Shadow->evaluatesParity());
    }

    public function test_flags_default_legacy(): void
    {
        Config::set('seo-content-ai.automation_migration', []);
        $flags = new AutomationMigrationFlags;
        self::assertSame(MigrationMode::Action, $flags->mode(AutomationMigrationFlags::SEO_ISSUE_ASSIGNMENT));
        self::assertFalse($flags->isLegacy(AutomationMigrationFlags::SEO_ISSUE_ASSIGNMENT));
    }

    public function test_content_conflict_guard_hash_mismatch(): void
    {
        $guard = new ArticleContentConflictGuard;
        $article = new SeoArticle;
        $article->body = 'alpha';
        $article->updated_at = Carbon::parse('2026-07-01T10:00:00+00:00');

        self::assertNull($guard->assertCompatible($article, [
            'expected_content_hash' => $guard->contentHash('alpha'),
        ]));

        $fail = $guard->assertCompatible($article, [
            'expected_content_hash' => $guard->contentHash('beta'),
        ]);
        self::assertInstanceOf(ActionResult::class, $fail);
        self::assertFalse($fail->success);
        self::assertSame('conflict_content_hash', $fail->error['code'] ?? null);
    }

    public function test_content_conflict_guard_version_match_ignores_stale_content_hash(): void
    {
        $guard = new ArticleContentConflictGuard;
        $article = new SeoArticle;
        $article->body = 'alpha';
        $article->document_version = 4;
        $article->updated_at = Carbon::parse('2026-07-01T10:00:00+00:00');

        self::assertNull($guard->assertCompatible($article, [
            'expected_document_version' => 4,
            'expected_content_hash' => $guard->contentHash('beta'),
        ]));
    }

    public function test_content_conflict_guard_updated_at_mismatch(): void
    {
        $guard = new ArticleContentConflictGuard;
        $article = new SeoArticle;
        $article->body = 'x';
        $article->updated_at = Carbon::parse('2026-07-01T10:00:00+00:00');

        $fail = $guard->assertCompatible($article, [
            'expected_updated_at' => '2026-07-01T09:00:00+00:00',
        ]);
        self::assertNotNull($fail);
        self::assertSame('conflict_updated_at', $fail->error['code'] ?? null);
    }

    public function test_content_conflict_guard_updated_at_soft_when_hash_matches(): void
    {
        $guard = new ArticleContentConflictGuard;
        $article = new SeoArticle;
        $article->body = 'same-body';
        $article->updated_at = Carbon::parse('2026-07-01T10:00:00+00:00');

        self::assertNull($guard->assertCompatible($article, [
            'expected_updated_at' => '2026-07-01T09:00:00+00:00',
            'expected_content_hash' => $guard->contentHash('same-body'),
        ]));
    }

    public function test_content_hash_stable(): void
    {
        $guard = new ArticleContentConflictGuard;
        self::assertSame(
            $guard->contentHash("  hello \n"),
            $guard->contentHash('hello'),
        );
    }

    public function test_origin_resolver_constants(): void
    {
        self::assertSame('seo_project_task', ArticleCreateOriginResolver::ORIGIN_SEO_PROJECT_TASK);
    }

    public function test_migrator_legacy_only_calls_legacy(): void
    {
        // Default cutover vẫn Action dù config = legacy.
        Config::set('seo-content-ai.automation_migration.seo_issue_assignment', 'legacy');

        $legacyCalls = 0;
        $actionCalls = 0;
        $parityCalls = 0;

        $migrator = $this->migrator();

        $out = $migrator->run(
            callerKey: AutomationMigrationFlags::SEO_ISSUE_ASSIGNMENT,
            legacyWrite: static function () use (&$legacyCalls): array {
                $legacyCalls++;

                return ['added' => 1];
            },
            actionWrite: static function () use (&$actionCalls): ActionResult {
                $actionCalls++;

                return ActionResult::success(output: ['added' => 1]);
            },
            parityExpected: static function () use (&$parityCalls): array {
                $parityCalls++;

                return ['added' => 1];
            },
            normalizeLegacy: static fn (mixed $v): array => (array) $v,
            normalizeExpected: static fn (array $v): array => $v,
            actionKey: 'seo.project_task.create_from_issue',
        );

        self::assertInstanceOf(ActionResult::class, $out);
        self::assertSame(0, $legacyCalls);
        self::assertSame(1, $actionCalls);
        self::assertSame(0, $parityCalls);
    }

    public function test_migrator_shadow_no_action_write_and_parity_match(): void
    {
        // Default cutover: shadow config vẫn Action.
        Config::set('seo-content-ai.automation_migration.seo_issue_assignment', 'shadow');

        $legacyCalls = 0;
        $actionCalls = 0;
        $parityCalls = 0;

        $migrator = $this->migrator();

        $out = $migrator->run(
            callerKey: AutomationMigrationFlags::SEO_ISSUE_ASSIGNMENT,
            legacyWrite: static function () use (&$legacyCalls): array {
                $legacyCalls++;

                return ['added' => 1, 'duplicate' => 0];
            },
            actionWrite: static function () use (&$actionCalls): ActionResult {
                $actionCalls++;

                return ActionResult::success(output: ['added' => 99]);
            },
            parityExpected: static function () use (&$parityCalls): array {
                $parityCalls++;

                return ['added' => 1, 'duplicate' => 0];
            },
            normalizeLegacy: static fn (mixed $v): array => (array) $v,
            normalizeExpected: static fn (array $v): array => $v,
            actionKey: 'seo.project_task.create_from_issue',
        );

        self::assertInstanceOf(ActionResult::class, $out);
        self::assertSame(0, $legacyCalls);
        self::assertSame(0, $parityCalls);
        self::assertSame(1, $actionCalls);
        self::assertSame(99, $out->output['added']);
    }

    public function test_migrator_shadow_logs_mismatch(): void
    {
        Config::set('seo-content-ai.automation_migration.keyword_project_assignment', 'shadow');

        $actionCalls = 0;
        $migrator = $this->migrator();

        $result = $migrator->run(
            callerKey: AutomationMigrationFlags::KEYWORD_PROJECT_ASSIGNMENT,
            legacyWrite: static fn (): array => ['added' => 1],
            actionWrite: static function () use (&$actionCalls): ActionResult {
                $actionCalls++;

                return ActionResult::success(output: ['added' => 0]);
            },
            parityExpected: static fn (): array => ['added' => 0],
            normalizeLegacy: static fn (mixed $v): array => (array) $v,
            normalizeExpected: static fn (array $v): array => $v,
            actionKey: 'keyword.assign_to_project',
        );

        self::assertInstanceOf(ActionResult::class, $result);
        self::assertSame(1, $actionCalls);
    }

    public function test_migrator_action_skips_legacy(): void
    {
        Config::set('seo-content-ai.automation_migration.seo_issue_assignment', 'action');

        $legacyCalls = 0;
        $actionCalls = 0;

        $migrator = $this->migrator();

        $result = $migrator->run(
            callerKey: AutomationMigrationFlags::SEO_ISSUE_ASSIGNMENT,
            legacyWrite: static function () use (&$legacyCalls): array {
                $legacyCalls++;

                return ['added' => 1];
            },
            actionWrite: static function () use (&$actionCalls): ActionResult {
                $actionCalls++;

                return ActionResult::success(output: ['added' => 2]);
            },
            parityExpected: static fn (): array => [],
            normalizeLegacy: static fn (mixed $v): array => (array) $v,
            normalizeExpected: static fn (array $v): array => $v,
        );

        self::assertInstanceOf(ActionResult::class, $result);
        self::assertSame(0, $legacyCalls);
        self::assertSame(1, $actionCalls);
        self::assertSame(2, $result->output['added']);
    }

    public function test_rollback_to_legacy_via_flag(): void
    {
        // Full cutover: flag env không còn rollback được về Legacy.
        Config::set('seo-content-ai.automation_migration.project_task_complete', 'action');
        $flags = new AutomationMigrationFlags;
        self::assertSame(MigrationMode::Action, $flags->mode(AutomationMigrationFlags::PROJECT_TASK_COMPLETE));

        Config::set('seo-content-ai.automation_migration.project_task_complete', 'legacy');
        self::assertSame(MigrationMode::Action, $flags->mode(AutomationMigrationFlags::PROJECT_TASK_COMPLETE));
        self::assertFalse($flags->isLegacy(AutomationMigrationFlags::PROJECT_TASK_COMPLETE));
    }
}
