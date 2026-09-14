<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectDraftPlanningItemsReadModel;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectTaskCanonicalInputBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\LegacyAddonPath;

/**
 * Rewrite/Improve SEO Audit reason is informational metadata — not an editable Create brief.
 */
final class DraftPlanningRewriteReasonReadOnlyTest extends TestCase
{
    public function test_read_model_separates_planning_description_from_rewrite_reason(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectDraftPlanningItemsReadModel::class))->getFileName(),
        );

        self::assertStringContainsString("'planning_description'", $src);
        self::assertStringContainsString("'rewrite_reason'", $src);
        self::assertStringContainsString("'can_edit_description' => \$isCreate", $src);
        self::assertStringContainsString('TYPE_REWRITE', $src);
        self::assertStringContainsString('TYPE_IMPROVE', $src);
        self::assertStringContainsString('rewrite_notes', $src);
        // Must not fold Rewrite reason into an editable Create brief path.
        self::assertStringContainsString('$isRewriteFamily', $src);
    }

    public function test_create_description_still_writes_secondary_description(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );
        $methodStart = strpos($page, 'function applyPlanningDescription');
        self::assertNotFalse($methodStart);
        $chunk = substr($page, $methodStart, 900);

        self::assertStringContainsString('isNewArticleType($task->type)', $chunk);
        self::assertStringContainsString('$task->secondary_description', $chunk);
        self::assertStringNotContainsString('$task->description = $value', $chunk);
        self::assertStringNotContainsString('$task->description = $description', $chunk);
    }

    public function test_backend_ignores_description_mutation_for_rewrite_and_improve(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );
        $methodStart = strpos($page, 'function updatePlanningField');
        self::assertNotFalse($methodStart);
        $chunk = substr($page, $methodStart, 2200);

        self::assertStringContainsString("\$field === 'description'", $chunk);
        self::assertStringContainsString('isNewArticleType($task->type)', $chunk);
        self::assertStringContainsString('Rewrite/Improve reason is informational', $chunk);

        $apply = new ReflectionMethod(ContentProjectSeoAuditPlanner::class, 'applyPlanningDescription');
        self::assertTrue($apply->isPrivate());

        $planner = (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->newInstanceWithoutConstructor();
        $rewrite = new SeoProjectTask;
        $rewrite->type = SeoProjectTask::TYPE_REWRITE;
        $rewrite->description = 'SEO reason A';
        $rewrite->rewrite_notes = 'SEO reason A';
        $rewrite->secondary_description = null;

        $improve = new SeoProjectTask;
        $improve->type = SeoProjectTask::TYPE_IMPROVE;
        $improve->description = 'SEO reason B';
        $improve->rewrite_notes = 'SEO reason B';
        $improve->secondary_description = null;

        $apply->setAccessible(true);
        $apply->invoke($planner, $rewrite, 'mutated brief');
        $apply->invoke($planner, $improve, 'mutated brief');

        self::assertSame('SEO reason A', $rewrite->description);
        self::assertSame('SEO reason A', $rewrite->rewrite_notes);
        self::assertNull($rewrite->secondary_description);
        self::assertSame('SEO reason B', $improve->description);
        self::assertSame('SEO reason B', $improve->rewrite_notes);
        self::assertNull($improve->secondary_description);
    }

    public function test_create_apply_planning_description_sets_secondary_description(): void
    {
        $apply = new ReflectionMethod(ContentProjectSeoAuditPlanner::class, 'applyPlanningDescription');
        $apply->setAccessible(true);
        $planner = (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->newInstanceWithoutConstructor();

        $create = new SeoProjectTask;
        $create->type = SeoProjectTask::TYPE_CREATE;
        $create->description = 'gallery stay';
        $create->secondary_description = 'old brief';

        $apply->invoke($planner, $create, 'new create brief');

        self::assertSame('new create brief', $create->secondary_description);
        self::assertSame('gallery stay', $create->description);
    }

    public function test_ui_rewrite_reason_is_read_only_create_remains_editable(): void
    {
        $items = LegacyAddonPath::read('resources/views/components/content-project-draft-items.blade.php');

        self::assertStringContainsString('row.can_edit_description && editing === row.id + \':description\'', $items);
        self::assertStringContainsString("startEdit(row, 'description')", $items);
        self::assertStringContainsString('if (field === \'description\' && !row.can_edit_description)', $items);
        self::assertStringContainsString('!row.can_edit_description && (row.rewrite_reason || row.description)', $items);
        self::assertStringContainsString('x-text="row.rewrite_reason || row.description"', $items);

        $readOnlyStart = strpos($items, '!row.can_edit_description && (row.rewrite_reason || row.description)');
        self::assertNotFalse($readOnlyStart);
        $readOnlyChunk = substr($items, $readOnlyStart, 450);
        self::assertStringContainsString('x-text="row.rewrite_reason || row.description"', $readOnlyChunk);
        self::assertStringNotContainsString('cursor-text', $readOnlyChunk);
        self::assertStringNotContainsString('@dblclick', $readOnlyChunk);
        self::assertStringNotContainsString('textarea', $readOnlyChunk);

        $createEditStart = strpos($items, 'row.can_edit_description && editing !== row.id + \':description\'');
        self::assertNotFalse($createEditStart);
        $createEditChunk = substr($items, $createEditStart, 450);
        self::assertStringContainsString('cursor-text', $createEditChunk);
        self::assertStringContainsString("@dblclick.prevent=\"startEdit(row, 'description')\"", $createEditChunk);
    }

    public function test_rewrite_generation_input_unchanged_ignores_description(): void
    {
        self::assertSame(
            'focus-kw',
            ContentProjectTaskCanonicalInputBuilder::forRewrite([
                'focus_keyword' => 'focus-kw',
                'post_title' => 'Title',
                'description' => 'SEO audit reason must not become rewrite input',
                'secondary_description' => 'also ignored',
                'rewrite_notes' => 'also ignored for subject',
            ]),
        );

        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectTaskCanonicalInputBuilder::class))->getFileName(),
        );
        $rewriteStart = strpos($src, 'function forRewrite');
        self::assertNotFalse($rewriteStart);
        $rewriteChunk = substr($src, $rewriteStart, 800);
        self::assertStringNotContainsString('description', $rewriteChunk);
        self::assertStringNotContainsString('rewrite_notes', $rewriteChunk);
        self::assertStringContainsString('focus_keyword', $rewriteChunk);
        self::assertStringContainsString('post_title', $rewriteChunk);
    }
}
