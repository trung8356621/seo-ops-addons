<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithDiscoverNewTopics;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ProjectRoot;

final class DiscoverNewTopicsUxAndLegacyGeneratorRetirementContractTest extends TestCase
{
    public function test_project_edit_ai_generator_action_removed(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectResource::class))->getFileName()
        );
        self::assertStringNotContainsString("Action::make('ai_generate_keywords')", $src);
        self::assertStringNotContainsString('generateKeywordsWithAi', $src);
        self::assertStringNotContainsString('SeoProjectKeywordAiGeneratorService', $src);
        self::assertFileDoesNotExist(
            ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectKeywordAiGeneratorService.php'
        );
    }

    public function test_keyword_discovery_structured_still_used_by_content_planning_planner_only(): void
    {
        $planner = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/NewContent/NewContentSuggestionPlannerService.php'
        );
        self::assertStringContainsString("keyword.discovery.structured", $planner);

        $resource = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectResource::class))->getFileName()
        );
        self::assertStringNotContainsString('keyword.discovery.structured', $resource);
    }

    public function test_new_topics_state_machine_and_topics_link_contract(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(InteractsWithDiscoverNewTopics::class))->getFileName()
        );
        self::assertStringContainsString('NEW_TOPICS_STATE_NOT_RUN', $src);
        self::assertStringContainsString('NEW_TOPICS_STATE_LOADING', $src);
        self::assertStringContainsString('NEW_TOPICS_STATE_COMPLETED', $src);
        self::assertStringContainsString('NEW_TOPICS_STATE_FAILED', $src);
        self::assertStringContainsString('newTopicsGenerationState', $src);
        self::assertStringContainsString('show_new_tab', $src);
        self::assertStringContainsString('keywordsTopicsUrlForAuditSite', $src);
        self::assertStringContainsString("getUrl('clusters')", $src);
        self::assertStringContainsString('appendSiteToUrl', $src);

        $blade = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/components/content-project-audit-notes.blade.php'
        );
        self::assertStringContainsString('data-new-topics-state', $blade);
        self::assertStringContainsString('showNewTab', $blade);
        self::assertStringContainsString('audit_notes_no_topics_warning', $blade);
        self::assertStringContainsString('audit_notes_open_topics', $blade);
        self::assertStringContainsString('target="_blank"', $blade);
        self::assertStringContainsString('data-audit-notes-open-topics', $blade);
        self::assertStringContainsString('data-discover-new-topics="1"', $blade);
        self::assertStringContainsString('new_topics_zero_result', $blade);
        self::assertStringContainsString('data-discover-new-topics-retry', $blade);
        self::assertStringContainsString('discoverCanRun', $blade);
        self::assertStringContainsString('data-discover-enabled', $blade);
        self::assertStringContainsString('new_topics_requires_topics', $blade);
        // New tab button only when showNewTab
        self::assertMatchesRegularExpression('/@if\s*\(\s*\$showNewTab\s*\)[\s\S]*audit_notes_tab_new/', $blade);
    }
}
