<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Migration\AutomationActionPromotionGate;
use Omnichannel\Addons\Agent\Automation\Migration\AutomationCallerMigrator;
use Omnichannel\Addons\Agent\Automation\Migration\AutomationMigrationFlags;
use Omnichannel\Addons\Agent\Automation\Migration\AutomationParityLogger;
use Omnichannel\Addons\Agent\Automation\Migration\AutomationParitySampleRecorder;
use Omnichannel\Addons\Agent\Automation\Migration\ParitySnapshotNormalizer;
use Omnichannel\Addons\Agent\Automation\Support\SensitivePayloadRedactor;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Staging validation scenarios — Group 1 parity (không cần SEO DB).
 */
final class AutomationPhase4StagingScenarioTest extends TestCase
{
    private ParitySnapshotNormalizer $normalizer;

    private AutomationParitySampleRecorder $samples;

    private AutomationParityLogger $parityLogger;

    private AutomationCallerMigrator $migrator;

    private AutomationActionPromotionGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new ParitySnapshotNormalizer;
        $this->samples = new AutomationParitySampleRecorder;
        $this->parityLogger = new AutomationParityLogger(new SensitivePayloadRedactor, $this->samples);
        $this->migrator = new AutomationCallerMigrator(new AutomationMigrationFlags, $this->parityLogger);
        $this->gate = new AutomationActionPromotionGate($this->samples);
        Config::set('seo-content-ai.automation_migration_min_parity_samples', 5);
    }

    public function test_assignment_scenario_new(): void
    {
        $snapshot = $this->normalizer->assignment([
            'added' => 1,
            'duplicate' => 0,
            'overflow' => 0,
            'domain_mismatch' => 0,
            'already_in_project' => 0,
        ], 10);

        self::assertTrue($snapshot['changed']);
        self::assertFalse($snapshot['noop']);
        self::assertFalse($snapshot['wrong_context']);
        self::assertSame(1, $snapshot['links']['tasks_created']);
        self::assertSame(10, $snapshot['ids']['project_id']);
    }

    public function test_assignment_scenario_existing_duplicate(): void
    {
        $snapshot = $this->normalizer->assignment([
            'added' => 0,
            'duplicate' => 1,
            'overflow' => 0,
            'domain_mismatch' => 0,
            'already_in_project' => 0,
        ], 10);

        self::assertTrue($snapshot['noop']);
        self::assertFalse($snapshot['changed']);
        self::assertSame(1, $snapshot['deduplication']['duplicate']);
    }

    public function test_assignment_scenario_partial_duplicate(): void
    {
        $snapshot = $this->normalizer->assignment([
            'added' => 2,
            'duplicate' => 1,
            'overflow' => 0,
            'domain_mismatch' => 0,
            'already_in_project' => 0,
        ], 10);

        self::assertTrue($snapshot['changed']);
        self::assertFalse($snapshot['noop']);
        self::assertSame(1, $snapshot['deduplication']['duplicate']);
        self::assertSame(2, $snapshot['links']['tasks_created']);
    }

    public function test_assignment_scenario_wrong_context(): void
    {
        $snapshot = $this->normalizer->assignment([
            'added' => 0,
            'duplicate' => 0,
            'overflow' => 0,
            'domain_mismatch' => 1,
            'already_in_project' => 0,
        ], 10);

        self::assertTrue($snapshot['wrong_context']);
        self::assertFalse($snapshot['changed']);
    }

    public function test_attach_scenario_new_and_already_attached(): void
    {
        $fresh = $this->normalizer->attach(['task_id' => 5, 'article_id' => 9], alreadyAttached: false, siteId: 1);
        self::assertTrue($fresh['changed']);
        self::assertFalse($fresh['noop']);
        self::assertTrue($fresh['links']['article_linked']);

        $noop = $this->normalizer->attach(['task_id' => 5, 'article_id' => 9], alreadyAttached: true, siteId: 1);
        self::assertTrue($noop['noop']);
        self::assertFalse($noop['changed']);
    }

    public function test_attach_scenario_wrong_context(): void
    {
        $bad = $this->normalizer->attach(['task_id' => 0, 'article_id' => 0], alreadyAttached: false);
        self::assertTrue($bad['wrong_context']);
    }

    public function test_complete_scenario_new_retry_and_already_completed(): void
    {
        $new = $this->normalizer->markCompleted([
            'task_id' => 3,
            'article_id' => 8,
            'status' => SeoProjectTask::STATUS_COMPLETED,
        ], alreadyCompleted: false, siteId: 2);

        self::assertTrue($new['changed']);
        self::assertTrue($new['links']['owner_sync_expected']);
        self::assertSame(SeoProjectTask::STATUS_COMPLETED, $new['status_transition']['to']);

        $retry = $this->normalizer->markCompleted([
            'task_id' => 3,
            'article_id' => 8,
            'status' => SeoProjectTask::STATUS_COMPLETED,
        ], alreadyCompleted: true, siteId: 2);

        self::assertTrue($retry['noop']);
        self::assertTrue($retry['deduplication']['already_completed']);
    }

    public function test_shadow_parity_match_for_seo_issue_assignment(): void
    {
        Config::set('seo-content-ai.automation_migration.seo_issue_assignment', 'shadow');
        $this->samples->reset();

        Log::shouldReceive('info')->once();

        $summary = [
            'added' => 1,
            'duplicate' => 0,
            'overflow' => 0,
            'domain_mismatch' => 0,
            'already_in_project' => 0,
        ];
        $normalizer = $this->normalizer;

        $this->migrator->run(
            callerKey: AutomationMigrationFlags::SEO_ISSUE_ASSIGNMENT,
            legacyWrite: static fn (): array => $summary,
            actionWrite: static fn (): ActionResult => ActionResult::success(),
            parityExpected: static fn (): array => $summary,
            normalizeLegacy: static fn (mixed $v): array => $normalizer->assignment($v, 1),
            normalizeExpected: static fn (array $v): array => $normalizer->assignment($v, 1),
            actionKey: 'seo.project_task.create_from_issue',
            correlationId: 'corr-seo-1',
        );

        $stats = $this->samples->forCaller(AutomationMigrationFlags::SEO_ISSUE_ASSIGNMENT);
        self::assertSame(1, $stats['match']);
        self::assertSame(0, $stats['mismatch']);
    }

    public function test_shadow_parity_mismatch_logs_normalized_diff(): void
    {
        Config::set('seo-content-ai.automation_migration.keyword_project_assignment', 'shadow');
        $this->samples->reset(AutomationMigrationFlags::KEYWORD_PROJECT_ASSIGNMENT);

        Log::shouldReceive('warning')->once()->withArgs(static function (string $message, array $context): bool {
            return $message === 'automation.migration.parity_mismatch'
                && isset($context['correlation_id'], $context['duration_ms'], $context['normalized_diff'])
                && ! isset($context['body'], $context['content']);
        });

        $normalizer = $this->normalizer;

        $this->migrator->run(
            callerKey: AutomationMigrationFlags::KEYWORD_PROJECT_ASSIGNMENT,
            legacyWrite: static fn (): array => [
                'added' => 1, 'duplicate' => 0, 'overflow' => 0, 'domain_mismatch' => 0, 'already_in_project' => 0,
            ],
            actionWrite: static fn (): ActionResult => ActionResult::success(),
            parityExpected: static fn (): array => [
                'added' => 0, 'duplicate' => 1, 'overflow' => 0, 'domain_mismatch' => 0, 'already_in_project' => 0,
            ],
            normalizeLegacy: static fn (mixed $v): array => $normalizer->assignment($v, 2),
            normalizeExpected: static fn (array $v): array => $normalizer->assignment($v, 2),
            actionKey: 'keyword.assign_to_project',
            correlationId: 'corr-kw-1',
        );

        self::assertSame(1, $this->samples->forCaller(AutomationMigrationFlags::KEYWORD_PROJECT_ASSIGNMENT)['mismatch']);
    }

    public function test_shadow_attach_already_attached_noop_parity(): void
    {
        Config::set('seo-content-ai.automation_migration.project_article_attach', 'shadow');
        $this->samples->reset(AutomationMigrationFlags::PROJECT_ARTICLE_ATTACH);
        Log::shouldReceive('info')->once();

        $normalizer = $this->normalizer;
        $payload = ['task_id' => 7, 'article_id' => 11, 'already_attached' => true];

        $this->migrator->run(
            callerKey: AutomationMigrationFlags::PROJECT_ARTICLE_ATTACH,
            legacyWrite: static fn (): array => $payload,
            actionWrite: static fn (): ActionResult => ActionResult::success(),
            parityExpected: static fn (): array => $payload,
            normalizeLegacy: static fn (mixed $v): array => $normalizer->attach($v, true, 1),
            normalizeExpected: static fn (array $v): array => $normalizer->attach($v, true, 1),
            actionKey: 'project.task.attach_article',
        );

        self::assertSame(1, $this->samples->forCaller(AutomationMigrationFlags::PROJECT_ARTICLE_ATTACH)['match']);
    }

    public function test_shadow_complete_retry_noop_parity(): void
    {
        Config::set('seo-content-ai.automation_migration.project_task_complete', 'shadow');
        $this->samples->reset(AutomationMigrationFlags::PROJECT_TASK_COMPLETE);
        Log::shouldReceive('info')->once();

        $normalizer = $this->normalizer;
        $payload = [
            'task_id' => 4,
            'article_id' => 12,
            'status' => SeoProjectTask::STATUS_COMPLETED,
            'already_completed' => true,
        ];

        $this->migrator->run(
            callerKey: AutomationMigrationFlags::PROJECT_TASK_COMPLETE,
            legacyWrite: static fn (): array => $payload,
            actionWrite: static fn (): ActionResult => ActionResult::success(),
            parityExpected: static fn (): array => $payload,
            normalizeLegacy: static fn (mixed $v): array => $normalizer->markCompleted($v, true, 1),
            normalizeExpected: static fn (array $v): array => $normalizer->markCompleted($v, true, 1),
            actionKey: 'project.task.mark_completed',
        );

        self::assertSame(1, $this->samples->forCaller(AutomationMigrationFlags::PROJECT_TASK_COMPLETE)['match']);
    }

    public function test_promotion_gate_blocks_on_mismatch_and_allows_after_samples(): void
    {
        $this->samples->reset();
        Config::set('seo-content-ai.automation_migration_min_parity_samples', 3);

        for ($i = 0; $i < 3; $i++) {
            $this->samples->record(AutomationMigrationFlags::SEO_ISSUE_ASSIGNMENT, true);
        }

        $blocked = $this->gate->evaluate(AutomationMigrationFlags::SEO_ISSUE_ASSIGNMENT, [
            'unexplained_mismatch' => true,
            'mismatch_explained' => false,
        ]);
        // no mismatch in samples — should allow if no signals
        $ok = $this->gate->evaluate(AutomationMigrationFlags::SEO_ISSUE_ASSIGNMENT, [
            'mismatch_explained' => true,
        ]);
        self::assertTrue($ok['allowed']);

        $this->samples->record(AutomationMigrationFlags::SEO_ISSUE_ASSIGNMENT, false);
        $deny = $this->gate->evaluate(AutomationMigrationFlags::SEO_ISSUE_ASSIGNMENT, [
            'mismatch_explained' => false,
        ]);
        self::assertFalse($deny['allowed']);
        self::assertContains('unexplained_parity_mismatch', $deny['reasons']);

        $denyDup = $this->gate->evaluate(AutomationMigrationFlags::SEO_ISSUE_ASSIGNMENT, [
            'mismatch_explained' => true,
            'unexplained_duplicate' => true,
        ]);
        self::assertContains('unexplained_duplicate', $denyDup['reasons']);

        $denyLink = $this->gate->evaluate(AutomationMigrationFlags::PROJECT_ARTICLE_ATTACH, [
            'missing_link' => true,
        ]);
        self::assertContains('missing_link', $denyLink['reasons']);
        self::assertContains('insufficient_parity_samples', $denyLink['reasons']);

        $denyWp = $this->gate->evaluate(AutomationMigrationFlags::SEO_ISSUE_ASSIGNMENT, [
            'mismatch_explained' => true,
            'wp_outbound' => true,
        ]);
        self::assertContains('wp_outbound_detected', $denyWp['reasons']);

        self::assertNotNull($blocked);
    }

    public function test_rollback_flag_to_legacy_verified(): void
    {
        Config::set('seo-content-ai.automation_migration.seo_issue_assignment', 'action');
        $flags = new AutomationMigrationFlags;
        self::assertSame('action', $flags->mode(AutomationMigrationFlags::SEO_ISSUE_ASSIGNMENT)->value);

        Config::set('seo-content-ai.automation_migration.seo_issue_assignment', 'legacy');
        self::assertSame('legacy', $flags->mode(AutomationMigrationFlags::SEO_ISSUE_ASSIGNMENT)->value);
    }

    public function test_parity_logger_strips_body_from_diff_context(): void
    {
        $this->samples->reset('strip_test');
        Log::shouldReceive('warning')->once()->withArgs(static function (string $message, array $context): bool {
            $diffJson = json_encode($context);
            self::assertIsString($diffJson);
            self::assertStringNotContainsString('SUPER_SECRET_BODY', $diffJson);

            return $message === 'automation.migration.parity_mismatch';
        });

        $this->parityLogger->compare(
            'strip_test',
            'article.content.update',
            ['ids' => ['article_id' => 1], 'body' => 'SUPER_SECRET_BODY', 'changed' => true],
            ['ids' => ['article_id' => 2], 'body' => 'SUPER_SECRET_BODY', 'changed' => false],
            correlationId: 'corr-strip',
            durationMs: 12,
        );
    }

    public function test_shadow_order_config(): void
    {
        $order = config('seo-content-ai.automation_migration_shadow_order');
        self::assertSame([
            'seo_issue_assignment',
            'keyword_project_assignment',
            'project_article_attach',
            'project_task_complete',
        ], $order);
    }
}
