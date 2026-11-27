<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultFeaturedSnippetOptimizePromptInstaller;
use Omnichannel\Addons\AiPrompt\Services\WorkflowParserService;
use Omnichannel\Addons\Content\Services\ArticleTableOptimizeService;
use Omnichannel\Addons\Seo\Services\SeoScoringEngine;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ArticleTableManagerPrimaryOptimizeTest extends TestCase
{
    public function test_optimize_hook_definition_and_installer_exist(): void
    {
        $def = dirname(__DIR__, 2).'/../ai-prompt/resources/prompt-hooks/v01/article.featured_snippet.optimize@0.1.0.json';
        self::assertFileExists($def);
        $json = json_decode((string) file_get_contents($def), true);
        self::assertIsArray($json);
        self::assertSame('article.featured_snippet.optimize', $json['key'] ?? null);
        self::assertTrue((bool) ($json['settings_visible'] ?? false));
        self::assertSame(
            'article.featured_snippet.optimize',
            DefaultFeaturedSnippetOptimizePromptInstaller::HOOK_KEY,
        );
        self::assertSame(
            DefaultFeaturedSnippetOptimizePromptInstaller::HOOK_KEY,
            ArticleTableOptimizeService::HOOK_KEY,
        );
        self::assertStringContainsString('{{table_html}}', DefaultFeaturedSnippetOptimizePromptInstaller::MARKDOWN);
        self::assertStringContainsString('{{target_data_rows}}', DefaultFeaturedSnippetOptimizePromptInstaller::MARKDOWN);
    }

    public function test_optimize_service_does_not_use_generate_hook(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleTableOptimizeService::class))->getFileName(),
        );
        self::assertStringContainsString('article.featured_snippet.optimize', $src);
        self::assertStringNotContainsString("'article.featured_snippet.generate'", $src);
        self::assertStringContainsString('validateOptimizedPreservesContent', $src);
        self::assertStringContainsString('resolveFeaturedSnippetTableScore', $src);
    }

    public function test_primary_attr_preferred_in_scoring_engines(): void
    {
        $small = '<table><thead><tr><th>A</th><th>B</th></tr></thead><tbody>'
            .'<tr><td>1</td><td>2</td></tr><tr><td>3</td><td>4</td></tr></tbody></table>';
        $rows = '';
        for ($i = 1; $i <= 10; $i++) {
            $rows .= '<tr><td>R'.$i.'</td><td>V'.$i.'</td></tr>';
        }
        $large = '<table data-seo-primary-table="1"><thead><tr><th>A</th><th>B</th></tr></thead><tbody>'
            .$rows.'</tbody></table>';
        $html = $small.$large;

        $engine = new SeoScoringEngine;
        $ref = new ReflectionClass($engine);
        $method = $ref->getMethod('bestFeaturedSnippetTierFromHtml');
        $method->setAccessible(true);
        $tier = $method->invoke($engine, $html, [
            'rows_min' => 3,
            'rows_range' => 6,
            'rows_max' => 10,
            'min_columns' => 2,
            'max_columns' => 5,
        ]);
        self::assertSame('excellent', $tier);

        $legacyHtml = $small.str_replace(' data-seo-primary-table="1"', '', $large);
        // Without primary, best-of-all still excellent because large table remains.
        $legacyTier = $method->invoke($engine, $legacyHtml, [
            'rows_min' => 3,
            'rows_range' => 6,
            'rows_max' => 10,
            'min_columns' => 2,
            'max_columns' => 5,
        ]);
        self::assertSame('excellent', $legacyTier);

        // Primary points at the weak table → score drops to none/average.
        $weakPrimary = str_replace('<table>', '<table data-seo-primary-table="1">', $small)
            .str_replace(' data-seo-primary-table="1"', '', $large);
        $weakTier = $method->invoke($engine, $weakPrimary, [
            'rows_min' => 3,
            'rows_range' => 6,
            'rows_max' => 10,
            'min_columns' => 2,
            'max_columns' => 5,
        ]);
        self::assertNotSame('excellent', $weakTier);
    }

    public function test_js_table_manager_contracts(): void
    {
        $utils = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/utils/articleTableManager.js',
        );
        $panel = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/components/ArticleTableManagerPanel.jsx',
        );
        $ext = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/utils/editorExtensions.js',
        );
        $sidebar = (string) file_get_contents(
            dirname(__DIR__, 3).'/seo/resources/js/editor/modules/seo/SeoSidebarPanel.jsx',
        );

        self::assertStringContainsString('data-seo-primary-table', $utils);
        self::assertStringContainsString('export function listArticleTablesFromBlocks', $utils);
        self::assertStringContainsString('export function setPrimaryTableInBlocks', $utils);
        self::assertStringContainsString('export function replaceTableInBlocks', $utils);
        self::assertStringContainsString('optimizeFeaturedSnippetTableFromEditor', $panel);
        self::assertStringContainsString('onApply', $panel);
        self::assertStringContainsString('seoPrimary', $ext);
        self::assertStringContainsString('ArticleTableManagerPanel', $sidebar);
    }

    public function test_edit_article_wires_optimize_preview_endpoint(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\EditArticle::class,
            ))->getFileName(),
        );
        self::assertStringContainsString('can_optimize_featured_snippet', $src);
        self::assertStringContainsString('optimizeFeaturedSnippetTableFromEditor', $src);
        self::assertStringContainsString('ArticleTableOptimizeService', $src);
    }
}
