<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationShape;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\ViewArticlePrompts;
use Omnichannel\Addons\Content\Services\ArticleExecutionHistory\ArticleExecutionHistoryService;
use Omnichannel\Addons\Content\Support\WritingSplitPreference;
use Omnichannel\Addons\Seo\Livewire\GlobalSeoBar;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;
use Tests\Support\ProjectRoot;

final class WritingSplitGlobalPreferenceAndHistoryUxTest extends TestCase
{
    public function test_writing_split_ignores_article_meta_authority(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(WritingSplitPreference::class))->getFileName());
        self::assertStringContainsString('UserMeta', $src);
        self::assertStringContainsString('persistForUserId', $src);
        self::assertStringContainsString('enabledForUserId', $src);
        self::assertStringContainsString('resolveForRun', $src);
        self::assertStringNotContainsString('articleMetas', $src);
        self::assertStringNotContainsString('enabledForArticle(', $src);
        self::assertFalse(WritingSplitPreference::enabledForArticleId(99));
        self::assertFalse(WritingSplitPreference::enabledFromVariables(['article_id' => 99]));
    }

    public function test_run_snapshot_is_immutable_over_later_preference(): void
    {
        self::assertTrue(WritingSplitPreference::resolveForRun([
            'writing_split_enabled' => true,
            'article_id' => 1,
        ], null));
        self::assertFalse(WritingSplitPreference::resolveForRun([
            'writing_split_enabled' => false,
        ], 42));
        self::assertSame(
            ArticleGenerationShape::Sectioned,
            ArticleGenerationShape::fromWritingSplitEnabled(true),
        );
        self::assertSame(
            ArticleGenerationShape::SinglePass,
            ArticleGenerationShape::fromWritingSplitEnabled(false),
        );
    }

    public function test_automation_without_actor_defaults_false(): void
    {
        self::assertFalse(WritingSplitPreference::resolveForRun([], null));
        self::assertFalse(WritingSplitPreference::enabledForUserId(null));
        self::assertFalse(WritingSplitPreference::enabledForUserId(0));
    }

    public function test_global_seo_bar_persists_user_preference_not_article(): void
    {
        $bar = (string) file_get_contents((new ReflectionClass(GlobalSeoBar::class))->getFileName());
        $blade = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/resources/views/livewire/global-seo-bar.blade.php',
        );

        self::assertStringContainsString('persistForUserId', $bar);
        self::assertStringContainsString('syncWritingSplitPreference', $bar);
        self::assertStringNotContainsString('writingSplitArticleId', $bar);
        self::assertStringNotContainsString('showWritingSplitToggle', $bar);
        self::assertStringNotContainsString('enabledForArticle', $bar);

        self::assertStringContainsString('x-filament::dropdown', $blade);
        self::assertStringContainsString('placement="bottom-end"', $blade);
        self::assertStringContainsString('writingSplitEnabled', $blade);
        self::assertStringContainsString('simulatedRole', $blade);
        self::assertStringContainsString('h-9 w-9', $blade);
        self::assertStringContainsString('options_heading', $blade);
        self::assertStringContainsString('ai_generation_heading', $blade);
        self::assertStringNotContainsString('showWritingSplitToggle', $blade);
    }

    public function test_execution_history_defaults_to_ai_calls_and_lazy_workflow(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(ViewArticlePrompts::class))->getFileName());
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/article-resource/pages/view-article-prompts.blade.php'),
        );

        self::assertStringContainsString("activeTab = 'ai_calls'", $src);
        self::assertStringContainsString("activeTab !== 'workflow'", $src);
        self::assertStringContainsString("\$activeTab === 'workflow' ? \$this->getExecutionRuns()", $blade);
        self::assertLessThan(
            strpos($blade, "setActiveTab('workflow')"),
            strpos($blade, "setActiveTab('ai_calls')"),
        );
    }

    public function test_workflow_ux_removes_global_run_cards_and_scopes_node_history(): void
    {
        $jsx = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/article-execution-history.jsx',
        );

        self::assertStringContainsString('collectNodeHistory', $jsx);
        self::assertStringContainsString('NodeHistoryList', $jsx);
        self::assertStringContainsString('nodeHistory', $jsx);
        self::assertStringContainsString('is_effective_success', $jsx);
        self::assertStringNotContainsString('activeRunId', $jsx);
        self::assertStringNotContainsString('setActiveRunId', $jsx);
        self::assertStringNotContainsString('runs.map((run) =>', $jsx);
    }

    public function test_workflow_history_excludes_tombstoned_ai_calls(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(ArticleExecutionHistoryService::class))->getFileName());
        self::assertStringContainsString('hiddenPromptResultIds', $src);
        self::assertStringContainsString('SeoArticleAiHistoryTombstone', $src);
        self::assertStringContainsString('isset($hiddenPromptResultIds[$resultId])', $src);
    }

    public function test_planner_snapshots_writing_split_preference_source(): void
    {
        $planner = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/Services/ArticleGenerationExecutionPlanner.php',
        );
        self::assertStringContainsString('WritingSplitPreference::resolveForRun', $planner);
        self::assertStringContainsString('SOURCE_WRITING_SPLIT_PREFERENCE', $planner);
        self::assertStringContainsString("merged['writing_split_enabled']", $planner);
        self::assertStringContainsString("merged['pass_mode']", $planner);
    }

    public function test_legacy_article_meta_catalog_marked_legacy(): void
    {
        $catalog = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Support/ArticleMetaKeyCatalog.php',
        );
        self::assertStringContainsString("'writing_split_enabled' =>", $catalog);
        self::assertStringContainsString('CLASS_LEGACY', $catalog);
        self::assertStringContainsString('user_meta.writing_split_enabled', $catalog);
    }
}
