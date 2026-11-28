<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectIdeaManager;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaManagerDistributionService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionPlannerService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\LegacyAddonPath;

final class IdeaManagerGenerateHistoryAndSafeRetryTest extends TestCase
{
    public function test_generate_history_is_global_authorized_newest_first_and_sanitized(): void
    {
        $svcSrc = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaManagerDistributionService::class))->getFileName(),
        );
        $pageSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectIdeaManager::class))->getFileName(),
        );

        self::assertStringContainsString('function generateHistory(', $pageSrc);
        self::assertStringContainsString('function retryDistributionRun(', $pageSrc);
        self::assertStringContainsString('->orderByDesc(\'id\')', $svcSrc);
        self::assertStringContainsString('SeoAccessControl::canAccessSite($siteId)', $svcSrc);
        self::assertStringContainsString('SeoContentProjectItemOrigin::query()', $svcSrc);
        self::assertStringContainsString('sanitizeErrorMessage(', $svcSrc);

        // Verify sensitive credential redaction at runtime via reflection.
        $ref = new ReflectionClass(IdeaManagerDistributionService::class);
        $instance = $ref->newInstanceWithoutConstructor();
        $sanitize = new ReflectionMethod(IdeaManagerDistributionService::class, 'sanitizeErrorMessage');
        $sanitize->setAccessible(true);

        $redacted = $sanitize->invoke(
            $instance,
            'HTTP 401 Authorization: Bearer secret-token-12345 key=AIzaSyAbCdEfGhIjKlMnOpQr https://api.test?api_key=abc123 sk-proj-1234567890abcdef',
        );
        self::assertStringNotContainsString('secret-token-12345', $redacted);
        self::assertStringNotContainsString('AIzaSyAbCdEfGhIjKlMnOpQr', $redacted);
        self::assertStringNotContainsString('abc123', $redacted);
        self::assertStringNotContainsString('sk-proj-1234567890abcdef', $redacted);
        self::assertStringContainsString('[REDACTED]', $redacted);
    }

    public function test_status_classification_separates_waiting_retry_from_stale(): void
    {
        $ref = new ReflectionClass(IdeaManagerDistributionService::class);
        $instance = $ref->newInstanceWithoutConstructor();
        $classify = new ReflectionMethod(IdeaManagerDistributionService::class, 'classifyRunValues');
        $classify->setAccessible(true);

        // Fresh waiting_retry (30s old) stays waiting_retry.
        self::assertSame(
            SeoContentProjectPlannerRun::STATUS_WAITING_RETRY,
            $classify->invoke($instance, 101, SeoContentProjectPlannerRun::STATUS_WAITING_RETRY, 30, [], 'database'),
        );

        // Old waiting_retry (20m) with no queued job becomes stale.
        self::assertSame(
            'stale',
            $classify->invoke($instance, 102, SeoContentProjectPlannerRun::STATUS_WAITING_RETRY, 1200, [], 'database'),
        );

        // If a queued job exists, it stays waiting_retry regardless of age.
        self::assertSame(
            SeoContentProjectPlannerRun::STATUS_WAITING_RETRY,
            $classify->invoke($instance, 102, SeoContentProjectPlannerRun::STATUS_WAITING_RETRY, 1200, [102 => true], 'database'),
        );

        // Fresh running (2m) stays running; old running (25m) with no queued job becomes stale.
        self::assertSame(
            SeoContentProjectPlannerRun::STATUS_RUNNING,
            $classify->invoke($instance, 103, SeoContentProjectPlannerRun::STATUS_RUNNING, 120, [], 'database'),
        );
        self::assertSame(
            'stale',
            $classify->invoke($instance, 104, SeoContentProjectPlannerRun::STATUS_RUNNING, 1500, [], 'database'),
        );
    }

    public function test_safe_retry_contract_locks_checks_queue_preserves_progress_and_respects_limits(): void
    {
        $svcSrc = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaManagerDistributionService::class))->getFileName(),
        );

        // Concurrency locks + authorization + queue inspection.
        self::assertStringContainsString('Cache::lock(\'idea_manager_retry_run:\'.$runId, 15)', $svcSrc);
        self::assertStringContainsString('Cache::lock(\'idea_manager_site_ai:\'.$siteId, 15)', $svcSrc);
        self::assertStringContainsString('findQueuedGeneratorRunIds(', $svcSrc);
        self::assertStringContainsString('\'code\' => \'job_already_queued\'', $svcSrc);
        self::assertStringContainsString('\'code\' => \'already_active\'', $svcSrc);
        self::assertStringContainsString('\'code\' => \'site_already_active\'', $svcSrc);
        self::assertStringContainsString('\'code\' => \'retry_limit_reached\'', $svcSrc);
        self::assertStringContainsString('$manualRetries >= 5', $svcSrc);

        // Preserves already-persisted Draft tasks and resumes same logical run.
        self::assertStringContainsString('SeoContentProjectItemOrigin::query()', $svcSrc);
        self::assertStringContainsString('$summary[\'task_ids\'] = $mergedTaskIds;', $svcSrc);
        self::assertStringContainsString('$summary[\'auto_continuation\'] = $isResume;', $svcSrc);
        self::assertStringContainsString('GenerateNewContentSuggestionsJob::dispatch(', $svcSrc);

        // Planner resume contract preserves remaining_note_items and chunk_ledger so successful Ideas do not regenerate.
        $plannerSrc = (string) file_get_contents(
            (string) (new ReflectionClass(NewContentSuggestionPlannerService::class))->getFileName(),
        );
        self::assertStringContainsString('$summarySeed[\'remaining_note_items\']', $plannerSrc);
        self::assertStringContainsString('$summarySeed[\'accepted_compact\']', $plannerSrc);
        self::assertStringContainsString('$chunkLedger->acceptedIdentities()', $plannerSrc);
    }

    public function test_frontend_history_dropdown_f5_restore_and_relaxed_lock_contract(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-idea-manager.blade.php');

        // History dropdown in Kho Idea header with F5 initialHistory and refresh button.
        self::assertStringContainsString('Lịch sử Generate', $blade);
        self::assertStringContainsString('initialHistory: @js($this->generateHistory())', $blade);
        self::assertStringContainsString('data-im-history-toggle="1"', $blade);
        self::assertStringContainsString('data-im-history-refresh="1"', $blade);
        self::assertStringContainsString('ideaManagerGenerateHistory(', $blade);
        self::assertStringContainsString('retryOrResume(row.planner_run_id)', $blade);
        self::assertStringContainsString('idea-manager-run-started', $blade);

        // Relaxed frontend lock: preview/check allowed during Generate, only submit disabled per-site.
        self::assertMatchesRegularExpression(
            '/canCheckNow\(\)\s*\{\s*return this\.targetSiteId > 0\s*&& this\.items\.length > 0\s*&& !this\.checking;\s*\}/',
            $blade,
        );
        self::assertStringContainsString('canDistributeNow()', $blade);
        self::assertStringContainsString('runsBySite: {},', $blade);
        self::assertStringContainsString('activeRunsBySite:', $blade);
    }

    public function test_generate_history_detail_url_includes_project_and_modal_contract(): void
    {
        $svcSrc = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaManagerDistributionService::class))->getFileName(),
        );
        $pageSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectIdeaManager::class))->getFileName(),
        );
        $detailPageSrc = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectPlannerRunDetail::class))->getFileName(),
        );
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-idea-manager.blade.php');

        // resolveRunDetailUrl must pass both project and run so ContentProjectPlannerRunDetail never 404s.
        self::assertStringContainsString("'project' => \$resolvedProjectId,", $svcSrc);
        self::assertStringContainsString("'run' => \$runId,", $svcSrc);
        self::assertStringNotContainsString("ContentProjectPlannerRunDetail::getUrl(['run' => \$runId]", $svcSrc);

        // Detail endpoint enforces authorization and reads canonical PromptResult safely without raw prompts.
        self::assertStringContainsString('function generateRunDetail(int $runId): array', $pageSrc);
        self::assertStringContainsString('function generateRunDetail(int $runId): array', $svcSrc);
        self::assertStringContainsString("if (\$siteId <= 0 || ! SeoAccessControl::canAccessSite(\$siteId)) {\n            abort(403);\n        }", $svcSrc);
        self::assertStringContainsString('SeoAccessControl::canAccessSite($runSiteId)', $detailPageSrc);
        self::assertStringContainsString("'prompt_result_status'", $svcSrc);
        self::assertStringContainsString("'provider'", $svcSrc);
        self::assertStringContainsString("'model'", $svcSrc);
        self::assertStringContainsString("'finished_at'", $svcSrc);

        // Blade opens modal via openDetail() and does not render Mở trang đầy đủ link.
        self::assertStringContainsString('data-im-history-detail="1"', $blade);
        self::assertStringContainsString('data-im-history-detail-modal="1"', $blade);
        self::assertStringContainsString('openDetail(row.planner_run_id)', $blade);
        self::assertStringContainsString('this.$wire.generateRunDetail(id)', $blade);
        self::assertStringNotContainsString('Mở trang đầy đủ', $blade);
    }

    public function test_persist_create_items_captures_auto_approve_and_deterministic_errors_fail_fast(): void
    {
        $plannerSrc = (string) file_get_contents(
            (string) (new ReflectionClass(NewContentSuggestionPlannerService::class))->getFileName(),
        );
        $svcSrc = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaManagerDistributionService::class))->getFileName(),
        );

        // $autoApprove must be imported into the omi_seo_ai transaction closure.
        self::assertMatchesRegularExpression(
            '/DB::connection\(\'omi_seo_ai\'\)->transaction\(function \(\) use \([^)]*\$autoApprove,[^)]*\)/s',
            $plannerSrc,
        );

        // Runtime / persistence errors must be treated as deterministic (never provider_transient retry loop).
        $ref = new ReflectionClass(NewContentSuggestionPlannerService::class);
        $planner = $ref->newInstanceWithoutConstructor();
        $isDet = new ReflectionMethod(NewContentSuggestionPlannerService::class, 'isDeterministicApplicationError');
        $isDet->setAccessible(true);

        self::assertTrue($isDet->invoke($planner, new \ErrorException('Undefined variable $autoApprove')));
        self::assertTrue($isDet->invoke($planner, new \TypeError('Bad type')));
        self::assertFalse($isDet->invoke($planner, new \RuntimeException('Temporary upstream timeout')));

        // Progress uses persisted createdDrafts capped by requested, never inflated AI retry attempts.
        self::assertStringContainsString(
            '$processed = $requested > 0 ? min($requested, $createdDrafts) : $createdDrafts;',
            $svcSrc,
        );
    }
}
