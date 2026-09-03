<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Http\Controllers\ArticleOutlineController;
use Omnichannel\Addons\Content\Services\ArticleOutlineResolver;
use Omnichannel\Addons\Content\Services\ArticleWriting\OutlineArticleWritingSourceProvider;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowStepRetryService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ProjectRoot;

/**
 * Cross-article outline comparison is retired. Outline remains structure for write/generate.
 * Duplicate-topic decisions belong to Keyword Intelligence / cluster / intent / Content Project.
 */
final class OutlineComparisonRetirementContractTest extends TestCase
{
    public function test_outline_tab_does_not_expose_compare_or_cross_article_duplicate_ui(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleOutlineTab.jsx',
        );

        self::assertStringNotContainsString('check-duplicates', $source);
        self::assertStringNotContainsString('checkDuplicatesUrl', $source);
        self::assertStringNotContainsString('outline_check_duplicates', $source);
        self::assertStringNotContainsString('handleToggleDuplicateMode', $source);
        self::assertStringNotContainsString('seo-outline-split', $source);
        self::assertStringNotContainsString('ReadOnlyOutlineTree', $source);
        self::assertStringContainsString('findLocalDuplicateHeadingKeys', $source);
        self::assertStringContainsString('outline_local_duplicate', $source);
    }

    public function test_editor_open_and_outline_tab_do_not_call_comparison_endpoint(): void
    {
        $editor = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/SeoArticleEditor.jsx',
        );
        $tab = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleOutlineTab.jsx',
        );

        self::assertStringNotContainsString('check-duplicates', $editor);
        self::assertStringNotContainsString('check-duplicates', $tab);
        self::assertStringNotContainsString('HeadingDuplicate', $editor);
    }

    public function test_outline_controller_does_not_invoke_comparison_services(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ArticleOutlineController::class))->getFileName(),
        );

        self::assertStringNotContainsString('HeadingDuplicateCheckerService', $source);
        self::assertStringNotContainsString('HeadingDuplicateCheckService', $source);
        self::assertStringNotContainsString('checkExactMatch', $source);
        self::assertStringNotContainsString('duplicateChecker', $source);
        self::assertStringContainsString("'deprecated' => true", $source);
        self::assertStringContainsString("'has_duplicate' => false", $source);
        self::assertStringContainsString("'duplicates' => []", $source);
        self::assertStringNotContainsString("'duplicates' => \$duplicates", $source);
    }

    public function test_article_generation_and_rerun_do_not_require_outline_comparison(): void
    {
        $retry = (string) file_get_contents(
            (new ReflectionClass(SeoProjectWorkflowStepRetryService::class))->getFileName(),
        );
        $writing = (string) file_get_contents(
            (new ReflectionClass(OutlineArticleWritingSourceProvider::class))->getFileName(),
        );
        $resolver = (string) file_get_contents(
            (new ReflectionClass(ArticleOutlineResolver::class))->getFileName(),
        );

        self::assertStringNotContainsString('HeadingDuplicate', $retry);
        self::assertStringNotContainsString('check-duplicates', $retry);
        self::assertStringNotContainsString('has_duplicate', $retry);
        self::assertStringNotContainsString('similarity', $retry);
        self::assertStringContainsString('persistOutlineStepResult', $retry);
        self::assertStringContainsString('articleGenerationInput->resolveForArticle', $retry);

        self::assertStringNotContainsString('HeadingDuplicate', $writing);
        self::assertStringContainsString('fromOutlineArtifact', $writing);

        self::assertStringNotContainsString('HeadingDuplicate', $resolver);
        self::assertStringContainsString('seo_article_outline', $resolver);
    }

    public function test_settings_ui_no_longer_configures_outline_skip_list(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/src/Filament/Pages/SeoSettingsEditor.php',
        );

        self::assertStringNotContainsString('Dò trùng lặp Outline', $source);
        self::assertStringNotContainsString('KEY_OUTLINE_SKIP_WORDS', $source);
    }

    public function test_mcp_catalog_does_not_expose_outline_comparison_tools(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/Agent/Mcp/ContentProjectMcpToolCatalog.php',
        );

        self::assertStringNotContainsString('compare_outline', $source);
        self::assertStringNotContainsString('check_outline_duplicate', $source);
        self::assertStringNotContainsString('outline_similarity', $source);
        self::assertStringNotContainsString('keyword_intelligence.get_cannibalization', $source);
        self::assertStringContainsString('keyword_intelligence.get_analysis_operation', $source);
    }

    public function test_legacy_comparison_services_remain_but_are_marked_deprecated(): void
    {
        $checker = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/src/Services/HeadingDuplicateCheckerService.php',
        );
        $check = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/src/Services/HeadingDuplicateCheckService.php',
        );

        self::assertStringContainsString('@deprecated', $checker);
        self::assertStringContainsString('@deprecated', $check);
        self::assertStringContainsString('Keyword Intelligence', $checker);
    }

    public function test_client_outline_still_detects_duplicate_headings_within_one_outline(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleEditorClientOutline.js',
        );

        self::assertStringContainsString('export function findLocalDuplicateHeadingKeys', $source);
    }
}
