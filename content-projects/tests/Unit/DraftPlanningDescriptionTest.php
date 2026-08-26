<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionParser;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionPlannerService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\ContentPlanningIntelligenceService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectRunItemService;
use Omnichannel\Addons\Seo\Services\SeoIssueProjectTaskAssignmentService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;
use Tests\Support\ProjectRoot;

final class DraftPlanningDescriptionTest extends TestCase
{
    public function test_parser_accepts_description_and_legacy_without_it(): void
    {
        $parser = new NewContentSuggestionParser;
        $withDesc = $parser->parse(json_encode([
            ['keyword' => 'seamless', 'suggested_title' => 'Seamless là gì?', 'description' => 'Explain seamless construction.', 'suggestion_reason' => 'gap'],
        ], JSON_THROW_ON_ERROR), 10);
        self::assertSame('Explain seamless construction.', $withDesc['candidates'][0]['description'] ?? null);
        self::assertSame('gap', $withDesc['candidates'][0]['suggestion_reason'] ?? null);

        $legacy = $parser->parse(json_encode([
            ['keyword' => 'kw', 'suggested_title' => 'Title', 'suggestion_reason' => 'why only'],
        ], JSON_THROW_ON_ERROR), 10);
        self::assertSame('', $legacy['candidates'][0]['description'] ?? 'missing');
        self::assertSame('why only', $legacy['candidates'][0]['suggestion_reason'] ?? null);
    }

    public function test_persist_stores_description_separately_from_suggestion_reason(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(NewContentSuggestionPlannerService::class))->getFileName(),
        );

        self::assertStringContainsString("\$candidate['description']", $src);
        self::assertStringContainsString("\$candidate['suggestion_reason']", $src);
        self::assertStringContainsString("'secondary_description' => \$brief !== '' ? \$brief : null", $src);
        self::assertStringContainsString('loai_san_pham', $src);
    }

    public function test_prompt_brief_and_hook_request_description(): void
    {
        $intel = (string) file_get_contents(
            (string) (new ReflectionClass(ContentPlanningIntelligenceService::class))->getFileName(),
        );
        $contract = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionStructuredResult::class))->getFileName(),
        );
        $hook = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/resources/prompt-hooks/v01/keyword.discovery.structured@0.1.0.json',
        );

        self::assertStringContainsString('description =', $intel);
        self::assertStringContainsString('1–3 sentence', $intel);
        self::assertStringContainsString('"description"', $contract);
        self::assertStringContainsString('OUTPUT CONTRACT', $contract);
        self::assertStringContainsString('description', $hook);
        self::assertStringContainsString('disambiguates short', $hook);
        self::assertStringContainsString('legacy_prompt_content', $hook);
        self::assertStringContainsString('gsc_signal', $hook);
        self::assertStringContainsString('canonical_default', $hook);
        self::assertStringContainsString('OUTPUT CONTRACT — STRICT', $hook);
    }

    public function test_seo_audit_assignment_sets_description_from_notes(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SeoIssueProjectTaskAssignmentService::class))->getFileName(),
        );

        self::assertStringContainsString('planningDescription', $src);
        self::assertStringContainsString("'description' => \$planningDescription !== '' ? \$planningDescription : null", $src);
    }

    public function test_generation_snapshot_includes_description(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectRunItemService::class))->getFileName(),
        );

        self::assertStringContainsString("'description' => \$task->description", $src);
        self::assertStringContainsString("'description' => (string) (\$task->description ?? '')", $src);
    }

    public function test_split_moves_task_without_clearing_task_description(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/Draft/SplitDraftContentProjectService.php',
        );

        // Project-level description may be null; task description must not be wiped.
        self::assertStringContainsString("'description' => null", $src);
        self::assertStringContainsString('source_draft_project_id', $src);
        self::assertStringNotContainsString("'description' => null,\n                    'keyword'", $src);
        self::assertDoesNotMatchRegularExpression(
            '/\$task->forceFill\(\[[^\]]*\'description\'\s*=>\s*null/s',
            $src,
        );
    }

    public function test_ui_shows_description_via_inline_dblclick_not_modal(): void
    {
        $items = LegacyAddonPath::read('resources/views/components/content-project-draft-items.blade.php');
        $page = LegacyAddonPath::read('resources/views/filament/pages/content-project-seo-audit-planner.blade.php');

        self::assertStringContainsString("row.description", $items);
        self::assertStringContainsString("startEdit(row, 'description')", $items);
        self::assertStringContainsString('updatePlanningField', $items);
        self::assertStringContainsString('showProductDescription', $items);
        self::assertStringContainsString('product_description', $items);
        self::assertStringContainsString('productDescriptionLabel', $items);
        self::assertStringContainsString('planning_product_description_label', $items);
        self::assertStringNotContainsString('openPlanningItemEdit', $items);
        self::assertStringNotContainsString('data-planning-edit-modal', $page);
        self::assertStringNotContainsString('planningEditDescription', $page);
    }
}
