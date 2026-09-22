<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\PromptExecutionProfileResolver;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithDiscoverNewTopics;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithDraftAiCalls;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\DiscoverNewTopicsService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\ContentProjectDraftAiCallHistoryService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ProjectRoot;

final class DiscoverNewTopicsHistoryAndProfileContractTest extends TestCase
{
    public function test_discover_and_topical_map_audit_resolve_to_reasoning_text(): void
    {
        $resolver = new PromptExecutionProfileResolver;
        self::assertSame(
            AiExecutionProfile::TextReasoning,
            $resolver->resolve(null, 'seo_audit.discover_new_topics'),
        );
        self::assertSame(
            AiExecutionProfile::TextReasoning,
            $resolver->resolve(null, 'seo_keywords.topical_map_audit'),
        );
        // Keyword Discovery (Content Planning Generate Ideas) stays longform.
        self::assertSame(
            AiExecutionProfile::TextLongform,
            $resolver->resolve(null, 'keyword.discovery.structured'),
        );
    }

    public function test_discover_links_single_prompt_result_via_planner_run_source(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(DiscoverNewTopicsService::class))->getFileName(),
        );
        self::assertStringContainsString('linkPromptResultToContentPlanHistory', $src);
        self::assertStringContainsString('ContentProjectPlannerRunService', $src);
        self::assertStringContainsString('SOURCE_DISCOVER_NEW_TOPICS', $src);
        self::assertStringContainsString("OPERATION = 'discover_new_topics'", $src);
        self::assertStringContainsString('recordExecuted', $src);
        self::assertSame(1, substr_count($src, 'promptHookBridge->run'));
        self::assertSame('discover_new_topics', SeoContentProjectPlannerRun::SOURCE_DISCOVER_NEW_TOPICS);

        $trait = (string) file_get_contents(
            (string) (new ReflectionClass(InteractsWithDiscoverNewTopics::class))->getFileName(),
        );
        self::assertStringContainsString('resolveNewContentProject', $trait);
        self::assertStringContainsString('DiscoverNewTopicsService::DEFAULT_COUNT', $trait);
        self::assertStringContainsString('applyGeneratedTopicBatch', $trait);
        self::assertStringContainsString('resolvePlanningProject', $src);
        self::assertStringContainsString('PlanningDraftResolver', $src);
    }

    public function test_draft_ai_history_includes_discover_source_and_type_filter(): void
    {
        $history = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectDraftAiCallHistoryService::class))->getFileName(),
        );
        self::assertStringContainsString('SOURCE_DISCOVER_NEW_TOPICS', $history);
        self::assertStringContainsString('TYPE_DISCOVER_NEW_TOPICS', $history);
        self::assertStringContainsString('seo_audit.discover_new_topics', $history);
        self::assertStringContainsString('draft_ai_calls_type_discover_new_topics', $history);
        self::assertStringContainsString("prompt?->hook_key", $history);
        self::assertStringNotContainsString('SOURCE_SEO_AUDIT', $history);

        $calls = (string) file_get_contents(
            (string) (new ReflectionClass(InteractsWithDraftAiCalls::class))->getFileName(),
        );
        self::assertStringContainsString('TYPE_DISCOVER_NEW_TOPICS', $calls);
        self::assertStringContainsString('type_options', $calls);

        $blade = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/pages/content-project-draft-ai-history.blade.php',
        );
        self::assertStringContainsString('type-options', $blade);
        self::assertStringContainsString('filter-type-wire="draftAiCallFilterType"', $blade);
    }
}
