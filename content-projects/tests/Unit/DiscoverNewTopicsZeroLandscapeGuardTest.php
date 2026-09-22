<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithDiscoverNewTopics;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\DiscoverNewTopicsService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\KeywordLandscape;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\KeywordLandscapeTopic;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ProjectRoot;

/**
 * Zero-topic landscape must block Discover New Topics (UI + service) without calling AI.
 */
final class DiscoverNewTopicsZeroLandscapeGuardTest extends TestCase
{
    public function test_landscape_allows_discovery_requires_topic_count_gt_zero(): void
    {
        $empty = new KeywordLandscape(9, [], null);
        self::assertFalse(DiscoverNewTopicsService::landscapeAllowsDiscovery($empty));
        self::assertSame(0, $empty->topicCount());

        $topic = new KeywordLandscapeTopic(
            id: 1,
            name: 'Bags',
            mcp: 10.0,
            dnaCount: 2,
            articleCount: 1,
            hasFocusArticle: true,
            coverage: 'partial',
            status: 'active',
            dna: [],
            updatedAt: null,
        );
        $ready = new KeywordLandscape(9, [$topic], '2026-09-01T00:00:00+00:00');
        self::assertTrue(DiscoverNewTopicsService::landscapeAllowsDiscovery($ready));
        self::assertSame(1, $ready->topicCount());
    }

    public function test_service_guards_empty_landscape_before_prompt_bridge(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(DiscoverNewTopicsService::class))->getFileName(),
        );
        $guardPos = strpos($src, 'landscapeAllowsDiscovery');
        $runPos = strpos($src, '$this->runPrompt(');
        self::assertNotFalse($guardPos);
        self::assertNotFalse($runPos);
        self::assertLessThan($runPos, $guardPos, 'empty landscape must block before runPrompt / PromptHookCallerBridge');
        self::assertStringContainsString('emptyLandscapeUserMessage', $src);
        self::assertStringContainsString('new_topics_requires_topics', $src);
        self::assertStringNotContainsString('DomainSeoMcpService', $src);
    }

    public function test_ui_disables_generate_until_landscape_has_topics(): void
    {
        $blade = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/components/content-project-audit-notes.blade.php'
        );
        self::assertStringContainsString('$discoverCanRun', $blade);
        self::assertStringContainsString('$total > 0', $blade);
        self::assertStringContainsString('data-discover-enabled', $blade);
        self::assertStringContainsString('@disabled(! $discoverCanRun)', $blade);
        self::assertStringContainsString('new_topics_requires_topics', $blade);
        self::assertStringContainsString('data-discover-disabled-hint', $blade);
        self::assertStringContainsString('audit_notes_open_topics', $blade);
        self::assertStringContainsString('data-audit-notes-open-topics', $blade);
        // Button stays rendered (not hidden) when zero topics.
        self::assertStringContainsString('data-discover-new-topics="1"', $blade);
        self::assertStringContainsString('$newGenerationState !== \'completed\'', $blade);
    }

    public function test_livewire_short_circuits_zero_topic_without_loading_state(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(InteractsWithDiscoverNewTopics::class))->getFileName(),
        );
        self::assertStringContainsString('auditNoteSuggestionTotal', $src);
        self::assertStringContainsString('new_topics_requires_topics', $src);
        self::assertStringContainsString('auditNoteSuggestionsReady', $src);
        self::assertStringContainsString('auditNoteSuggestionsLoading', $src);
        // Early return appears before flipping generation state to loading.
        $warnPos = strpos($src, 'new_topics_requires_topics');
        $loadingAssign = strpos($src, "newTopicsGenerationState = self::NEW_TOPICS_STATE_LOADING");
        self::assertNotFalse($warnPos);
        self::assertNotFalse($loadingAssign);
        self::assertLessThan($loadingAssign, $warnPos);
    }
}
