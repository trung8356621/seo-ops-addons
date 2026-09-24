<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultTopicalMapAuditPromptInstaller;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\TopicalMapAuditHistoryLinker;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\ContentProjectDraftAiCallHistoryService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class TopicalMapAuditHistoryContractTest extends TestCase
{
    public function test_source_and_type_constants_registered(): void
    {
        self::assertSame('topical_map_audit', SeoContentProjectPlannerRun::SOURCE_TOPICAL_MAP_AUDIT);
        self::assertSame('topical_map_audit', ContentProjectDraftAiCallHistoryService::TYPE_TOPICAL_MAP_AUDIT);
        self::assertContains(
            SeoContentProjectPlannerRun::SOURCE_TOPICAL_MAP_AUDIT,
            ContentProjectDraftAiCallHistoryService::AI_PLANNER_SOURCES,
        );
    }

    public function test_history_linker_records_planner_run_with_hook_metadata(): void
    {
        $src = (string) file_get_contents((string) (new ReflectionClass(TopicalMapAuditHistoryLinker::class))->getFileName());
        self::assertStringContainsString('SOURCE_TOPICAL_MAP_AUDIT', $src);
        self::assertStringContainsString('recordExecuted', $src);
        self::assertStringContainsString('PlanningDraftResolver', $src);
        self::assertStringContainsString('DefaultTopicalMapAuditPromptInstaller::HOOK_KEY', $src);
        self::assertStringContainsString('hook_version', $src);
        self::assertStringContainsString('prompt_result_id', $src);
        self::assertSame('seo_keywords.topical_map_audit', DefaultTopicalMapAuditPromptInstaller::HOOK_KEY);

        // Failed runs must still pass prompt_result_id when PromptRunner already persisted it.
        $auditSrc = (string) file_get_contents((string) (new ReflectionClass(
            \Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapAuditService::class,
        ))->getFileName());
        self::assertStringContainsString('failFromThrowable', $auditSrc);
        self::assertStringContainsString("context['prompt_result_id']", $auditSrc);
    }

    public function test_ai_history_url_resolves_existing_shared_draft_without_create(): void
    {
        $src = (string) file_get_contents((string) (new ReflectionClass(TopicalMapAuditHistoryLinker::class))->getFileName());
        self::assertStringContainsString('findExistingPlanningProject', $src);
        self::assertStringContainsString('resolveAiHistoryUrl', $src);
        self::assertStringContainsString('ContentProjectDraftAiHistory::urlForProject', $src);
        self::assertStringContainsString('findCanonicalSharedDraft', $src);

        // Navigation must never create Shared Draft; ensure stays on audit link path only.
        $resolveAiHistory = $this->methodBody($src, 'resolveAiHistoryUrl');
        self::assertStringContainsString('findExistingPlanningProject', $resolveAiHistory);
        self::assertStringNotContainsString('ensureSharedDraft', $resolveAiHistory);

        $findExisting = $this->methodBody($src, 'findExistingPlanningProject');
        self::assertStringContainsString('findCanonicalSharedDraft', $findExisting);
        self::assertStringNotContainsString('ensureSharedDraft', $findExisting);

        $resolvePlanning = $this->methodBody($src, 'resolvePlanningProject');
        self::assertStringContainsString('findExistingPlanningProject', $resolvePlanning);
        self::assertStringContainsString('ensureSharedDraft', $resolvePlanning);
    }

    public function test_draft_history_maps_topical_map_audit_type_label_and_filter(): void
    {
        $src = (string) file_get_contents((string) (new ReflectionClass(ContentProjectDraftAiCallHistoryService::class))->getFileName());
        self::assertStringContainsString('TYPE_TOPICAL_MAP_AUDIT', $src);
        self::assertStringContainsString('seo_keywords.topical_map_audit', $src);
        self::assertStringContainsString('draft_ai_calls_type_topical_map_audit', $src);
        self::assertStringContainsString('SOURCE_TOPICAL_MAP_AUDIT', $src);
    }

    private function methodBody(string $src, string $method): string
    {
        $pattern = '/function\s+'.preg_quote($method, '/').'\s*\([^)]*\)[^{]*\{/';
        if (! preg_match($pattern, $src, $m, PREG_OFFSET_CAPTURE)) {
            self::fail("Method {$method} not found");
        }
        $start = (int) $m[0][1] + strlen($m[0][0]) - 1;
        $depth = 0;
        $len = strlen($src);
        for ($i = $start; $i < $len; $i++) {
            $ch = $src[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $start, $i - $start + 1);
                }
            }
        }

        self::fail("Unclosed method body for {$method}");
    }
}
