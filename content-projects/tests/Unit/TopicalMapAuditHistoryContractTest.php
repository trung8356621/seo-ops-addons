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
    }

    public function test_draft_history_maps_topical_map_audit_type_label_and_filter(): void
    {
        $src = (string) file_get_contents((string) (new ReflectionClass(ContentProjectDraftAiCallHistoryService::class))->getFileName());
        self::assertStringContainsString('TYPE_TOPICAL_MAP_AUDIT', $src);
        self::assertStringContainsString('seo_keywords.topical_map_audit', $src);
        self::assertStringContainsString('draft_ai_calls_type_topical_map_audit', $src);
        self::assertStringContainsString('SOURCE_TOPICAL_MAP_AUDIT', $src);
    }
}
