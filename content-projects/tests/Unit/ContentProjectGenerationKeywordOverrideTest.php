<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectLifecyclePhase;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SetItemGenerationKeywordOverrideCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBusRegistrar;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\GenerateProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\SetItemGenerationKeywordOverrideHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectBulkGenerationPlanner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGeneratePendingPreview;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemGenerationClassifier;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemGenerationDecision;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectFreshKeywordRestart;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectGenerationKeyword;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectLifecycle;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectGenerationKeywordOverrideTest extends TestCase
{
    private ContentProjectItemGenerationClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new ContentProjectItemGenerationClassifier(new ContentProjectLifecycle);
    }

    public function test_effective_keyword_prefers_override(): void
    {
        $task = new SeoProjectTask([
            'keyword' => 'bảo quản balo da',
            'generation_keyword_override' => 'cách bảo quản balo da',
        ]);

        self::assertSame('bảo quản balo da', ContentProjectGenerationKeyword::originalKeyword($task));
        self::assertSame('cách bảo quản balo da', ContentProjectGenerationKeyword::effective($task));
    }

    public function test_normalize_override_input_clears_when_matches_original(): void
    {
        $task = new SeoProjectTask(['keyword' => 'balo học sinh']);

        self::assertNull(ContentProjectGenerationKeyword::normalizeOverrideInput($task, 'balo học sinh'));
        self::assertSame('balo mới', ContentProjectGenerationKeyword::normalizeOverrideInput($task, 'balo mới'));
    }

    public function test_dirty_when_last_generated_differs_from_effective(): void
    {
        $task = new SeoProjectTask([
            'keyword' => 'A',
            'generation_keyword_override' => 'B',
        ]);

        self::assertTrue(ContentProjectGenerationKeyword::isDirty($task, 'A', true));
        self::assertFalse(ContentProjectGenerationKeyword::isDirty($task, 'B', true));
        self::assertFalse(ContentProjectGenerationKeyword::isDirty($task, 'B', false));
    }

    public function test_last_generated_from_snapshot_prefers_effective_key(): void
    {
        $keyword = ContentProjectGenerationKeyword::lastGeneratedFromSnapshot([
            'keyword' => 'old',
            ContentProjectGenerationKeyword::SNAPSHOT_EFFECTIVE_KEY => 'new effective',
        ]);

        self::assertSame('new effective', $keyword);
    }

    public function test_generated_item_with_dirty_keyword_is_runnable(): void
    {
        $decision = $this->classifier->classifySnapshot([
            'task_id' => 10,
            'type' => SeoProjectTask::TYPE_CREATE,
            'status' => SeoProjectTask::STATUS_COMPLETED,
            'article_id' => 55,
            'keyword' => 'B',
            'article_has_body' => true,
            'lifecycle_phase' => ContentProjectLifecyclePhase::Review->value,
            'successful_execution' => true,
            'generation_keyword_dirty' => true,
            'last_generated_keyword' => 'A',
        ]);

        self::assertTrue($decision->shouldRun());
        self::assertSame(ContentProjectGenerationKeyword::REASON_DIRTY, $decision->reason);
    }

    public function test_published_item_with_dirty_keyword_stays_skipped(): void
    {
        $decision = $this->classifier->classifySnapshot([
            'task_id' => 11,
            'type' => SeoProjectTask::TYPE_CREATE,
            'status' => SeoProjectTask::STATUS_COMPLETED,
            'article_id' => 56,
            'keyword' => 'B',
            'article_has_body' => true,
            'lifecycle_phase' => ContentProjectLifecyclePhase::Published->value,
            'successful_execution' => true,
            'generation_keyword_dirty' => true,
        ]);

        self::assertFalse($decision->shouldRun());
        self::assertSame('lifecycle_published', $decision->reason);
    }

    public function test_bulk_planner_partitions_restart_vs_generate(): void
    {
        $preview = new ContentProjectGeneratePendingPreview(1, 3, [
            new ContentProjectItemGenerationDecision(1, ContentProjectItemGenerationDecision::ACTION_RUN, 'never_generated', 'pending', SeoProjectTask::TYPE_CREATE),
            new ContentProjectItemGenerationDecision(2, ContentProjectItemGenerationDecision::ACTION_RUN, ContentProjectGenerationKeyword::REASON_DIRTY, 'completed', SeoProjectTask::TYPE_CREATE),
            new ContentProjectItemGenerationDecision(3, ContentProjectItemGenerationDecision::ACTION_SKIP, 'valid_article_output', 'completed', SeoProjectTask::TYPE_CREATE),
        ], true, false);

        $planner = new ContentProjectBulkGenerationPlanner;
        $partition = $planner->partition($preview, [1, 2, 3]);

        self::assertSame([1], $partition['generate_ids']);
        self::assertSame([2], $partition['restart_with_keyword_ids']);
    }

    public function test_command_bus_registers_override_handler(): void
    {
        $registrar = (string) file_get_contents((new ReflectionClass(ContentProjectCommandBusRegistrar::class))->getFileName());
        self::assertStringContainsString('SetItemGenerationKeywordOverrideCommand::class', $registrar);
        self::assertStringContainsString('SetItemGenerationKeywordOverrideHandler::class', $registrar);

        $command = new SetItemGenerationKeywordOverrideCommand(10, 'kw mới');
        self::assertSame('content_project.set_generation_keyword_override', $command->name());
    }

    public function test_generate_handler_partitions_keyword_restart(): void
    {
        $handler = (string) file_get_contents((new ReflectionClass(GenerateProjectItemsHandler::class))->getFileName());
        self::assertStringContainsString('ContentProjectBulkGenerationPlanner', $handler);
        self::assertStringContainsString('RestartGenerationWithKeywordCommand', $handler);
        self::assertStringContainsString('ContentProjectGenerationKeyword::effective', $handler);
    }

    public function test_commit_canonical_keyword_preserves_original_task_keyword(): void
    {
        $support = (string) file_get_contents((new ReflectionClass(ContentProjectFreshKeywordRestart::class))->getFileName());
        self::assertStringContainsString('generation_keyword_override', $support);
        self::assertStringNotContainsString('$task->keyword = $keyword', $support);
    }

    public function test_ui_keyword_cell_component_exists(): void
    {
        $blade = (string) file_get_contents(
            dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/components/content-project-keyword-cell.blade.php',
        );
        self::assertStringContainsString('saveGenerationKeywordOverride', $blade);
        self::assertStringContainsString('revertGenerationKeywordOverride', $blade);
        self::assertStringContainsString('@dblclick.stop', $blade);
    }
}
