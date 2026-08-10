<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\ContentProjects\Console\BackfillContentProjectRunItemsCommand;
use Omnichannel\Addons\AiPrompt\Services\ArticlePromptRunHistoryService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectRunConsolidationService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectRunItemsReader;
use Omnichannel\Addons\ContentProjects\Support\SeoProjectRunItemViewData;
use Omnichannel\Addons\ContentProjects\Support\SeoProjectRunItemsDisplayPresenter;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class SeoProjectPhase3BReadPathTest extends TestCase
{
    public function test_reader_source_constants_are_exclusive(): void
    {
        $this->assertSame('database', SeoProjectRunItemsReader::SOURCE_DATABASE);
        $this->assertSame('legacy_json', SeoProjectRunItemsReader::SOURCE_LEGACY_JSON);
        $this->assertSame('empty', SeoProjectRunItemsReader::SOURCE_EMPTY);
        $this->assertNotSame(
            SeoProjectRunItemsReader::SOURCE_DATABASE,
            SeoProjectRunItemsReader::SOURCE_LEGACY_JSON,
        );
    }

    public function test_reader_for_run_never_merges_per_docblock(): void
    {
        $ref = new ReflectionClass(SeoProjectRunItemsReader::class);
        $doc = (string) $ref->getDocComment();

        $this->assertStringContainsString('khÃ´ng bao giá» merge', mb_strtolower($doc));
        $this->assertTrue($ref->hasMethod('forRun'));
        $this->assertTrue($ref->hasMethod('usesLegacyFallback'));
        $this->assertTrue($ref->hasMethod('sourceForRun'));
        $this->assertTrue($ref->hasMethod('detectInconsistency'));
        $this->assertTrue($ref->hasMethod('aggregateCounters'));
    }

    public function test_view_data_ui_key_prefers_run_item_id(): void
    {
        $row = new SeoProjectRunItemViewData(
            runItemId: 42,
            taskId: 7,
            articleId: null,
            action: 'article.create',
            type: 'new_keyword',
            postType: null,
            sourceContent: 'kw',
            status: 'success',
            attempt: 1,
            message: '',
            errorCode: null,
            errorMessage: null,
            articleEditUrl: null,
            targetDate: null,
            description: null,
            isLegacy: false,
            source: SeoProjectRunItemsReader::SOURCE_DATABASE,
            taskExists: true,
            canRetry: true,
            canArchive: false,
        );

        $this->assertSame('run-item-42', $row->uiKey());
        $this->assertSame('run-item-42', $row->toArray()['id']);
        $this->assertFalse((bool) $row->toArray()['is_legacy']);
    }

    public function test_view_data_legacy_key_is_stable_hash_not_index(): void
    {
        $a = new SeoProjectRunItemViewData(
            runItemId: null,
            taskId: 9,
            articleId: null,
            action: 'article.create',
            type: 'new_keyword',
            postType: null,
            sourceContent: 'Same Keyword',
            status: 'failed',
            attempt: 1,
            message: '',
            errorCode: null,
            errorMessage: null,
            articleEditUrl: null,
            targetDate: null,
            description: null,
            isLegacy: true,
            source: SeoProjectRunItemsReader::SOURCE_LEGACY_JSON,
            taskExists: false,
            canRetry: false,
            canArchive: false,
            extra: ['run_id' => 15, 'legacy_index' => 0],
        );
        $b = new SeoProjectRunItemViewData(
            runItemId: null,
            taskId: 9,
            articleId: null,
            action: 'article.create',
            type: 'new_keyword',
            postType: null,
            sourceContent: 'same keyword',
            status: 'failed',
            attempt: 1,
            message: '',
            errorCode: null,
            errorMessage: null,
            articleEditUrl: null,
            targetDate: null,
            description: null,
            isLegacy: true,
            source: SeoProjectRunItemsReader::SOURCE_LEGACY_JSON,
            taskExists: false,
            canRetry: false,
            canArchive: false,
            extra: ['run_id' => 15, 'legacy_index' => 0],
        );

        $this->assertStringStartsWith('legacy-15-', $a->uiKey());
        $this->assertSame($a->uiKey(), $b->uiKey());
        $this->assertFalse($a->canRetry);
        $this->assertFalse($a->canArchive);
    }

    public function test_presenter_keeps_two_tasks_same_source(): void
    {
        $rows = (new SeoProjectRunItemsDisplayPresenter)->consolidate([
            [
                'run_item_id' => 1,
                'task_id' => 10,
                'source_content' => 'dup',
                'status' => 'success',
            ],
            [
                'run_item_id' => 2,
                'task_id' => 11,
                'source_content' => 'dup',
                'status' => 'success',
            ],
        ]);

        $this->assertCount(2, $rows);
    }

    public function test_view_run_keeps_public_method_names(): void
    {
        $view = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Filament/Resources/SeoProjectResource/Pages/ViewSeoProjectRun.php',
        );
        // Legacy Run Detail is redirect-only; queue methods no longer public on the page.
        self::assertStringContainsString('getProjectWorkspaceUrl', $view);
        self::assertStringContainsString('function mount(', $view);
        self::assertStringNotContainsString('function getAllItems', $view);
        self::assertStringNotContainsString('function archiveItem', $view);
    }

    public function test_get_all_items_doc_forbids_pending_union(): void
    {
        $reader = new ReflectionClass(SeoProjectRunItemsReader::class);
        self::assertTrue($reader->hasMethod('forRun'));
        self::assertTrue($reader->hasMethod('forRunAsArrays'));
        $view = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Filament/Resources/SeoProjectResource/Pages/ViewSeoProjectRun.php',
        );
        self::assertStringNotContainsString('function getAllItems', $view);
    }

    public function test_consolidation_service_is_marked_deprecated(): void
    {
        $ref = new ReflectionClass(SeoProjectRunConsolidationService::class);
        $doc = (string) $ref->getDocComment();

        $this->assertStringContainsString('@deprecated', $doc);
        $this->assertStringContainsString('SeoProjectRunItemsReader', $doc);
        $this->assertTrue($ref->hasMethod('collectMergedItems'));

        $merge = new ReflectionMethod(SeoProjectRunConsolidationService::class, 'collectMergedItems');
        $this->assertTrue($merge->isPrivate());
    }

    public function test_consolidation_merge_bucket_prefers_task_id(): void
    {
        $method = new ReflectionMethod(SeoProjectRunConsolidationService::class, 'mergeBucketKey');
        $method->setAccessible(true);
        $service = $this->app->make(SeoProjectRunConsolidationService::class);

        $taskKey = $method->invoke($service, [
            'run_item_id' => 99,
            'task_id' => 7,
            'action' => 'article.create',
            'type' => 'new_keyword',
            'source_content' => 'x',
        ]);
        $otherTask = $method->invoke($service, [
            'run_item_id' => 100,
            'task_id' => 8,
            'action' => 'article.create',
            'type' => 'new_keyword',
            'source_content' => 'x',
        ]);

        $this->assertSame('task:7|article.create', $taskKey);
        $this->assertNotSame($taskKey, $otherTask);
    }

    public function test_article_history_service_references_run_items_model(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(ArticlePromptRunHistoryService::class))->getFileName() ?: '',
        );

        $this->assertIsString($source);
        $this->assertStringContainsString('SeoProjectRunItem', $source);
        $this->assertStringContainsString('runsWithDbSet', $source);
        $this->assertStringContainsString('seenRunItemIds', $source);
        $this->assertStringContainsString('seenLinkIds', $source);
    }

    public function test_backfill_command_signature_and_options(): void
    {
        $command = new BackfillContentProjectRunItemsCommand;
        $definition = $command->getDefinition();

        $this->assertSame('content-project:backfill-run-items', $command->getName());
        $this->assertTrue($definition->hasOption('dry-run'));
        $this->assertTrue($definition->hasOption('run-id'));
        $this->assertTrue($definition->hasOption('project-id'));
        $this->assertTrue($definition->hasOption('chunk'));
        $this->assertTrue($definition->hasOption('force-inconsistent'));
    }

    public function test_soft_deletes_enabled_on_task_model_phase_3c1(): void
    {
        $traits = class_uses_recursive(\Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::class);

        $this->assertIsArray($traits);
        $this->assertArrayHasKey(\Illuminate\Database\Eloquent\SoftDeletes::class, $traits);
    }
}
