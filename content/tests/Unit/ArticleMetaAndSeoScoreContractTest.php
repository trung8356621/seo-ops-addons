<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Content\Console\ArticleMetaAuditCommand;
use Omnichannel\Addons\Content\Console\ArticleMetaCleanupCommand;
use Omnichannel\Addons\Seo\Http\Controllers\ArticleSeoScorePreviewController;
use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\Content\Support\ArticleMetaKeyCatalog;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Contract: meta catalog + SEO score parity architecture (no DB).
 */
final class ArticleMetaAndSeoScoreContractTest extends TestCase
{
    public function test_cleanup_candidates_have_zero_readers(): void
    {
        foreach (ArticleMetaKeyCatalog::cleanupCandidates() as $key) {
            $def = ArticleMetaKeyCatalog::definition($key);
            self::assertNotNull($def);
            self::assertSame(ArticleMetaKeyCatalog::CLASS_ORPHAN, $def['class']);
            self::assertTrue($def['cleanup']);
            self::assertSame([], $def['readers'], "Cleanup key [{$key}] must have zero readers");
        }
    }

    public function test_featured_image_keys_are_not_confused_with_body_images(): void
    {
        $featured = ArticleMetaKeyCatalog::definition('wp_featured_image_url');
        $bodyImages = ArticleMetaKeyCatalog::definition('wp_post_images');

        self::assertSame(ArticleMetaKeyCatalog::CLASS_CANONICAL, $featured['class']);
        self::assertSame(ArticleMetaKeyCatalog::CLASS_CACHE, $bodyImages['class']);
        self::assertNotSame($featured['purpose'], $bodyImages['purpose']);
    }

    public function test_seo_score_version_and_meta_keys_defined(): void
    {
        self::assertNotSame('', SeoScoringRulesRegistry::SCORE_VERSION);
        self::assertSame('seo_analyzed_content_hash', SeoScoringRulesRegistry::META_KEY_ANALYZED_CONTENT_HASH);
        self::assertSame('seo_score_version', SeoScoringRulesRegistry::META_KEY_SCORE_VERSION);
        self::assertSame('seo_score_calculated_at', SeoScoringRulesRegistry::META_KEY_SCORE_CALCULATED_AT);
        self::assertNotNull(ArticleMetaKeyCatalog::definition(SeoScoringRulesRegistry::META_KEY_ANALYZED_CONTENT_HASH));
    }

    public function test_commands_registered_by_name(): void
    {
        $audit = new ArticleMetaAuditCommand;
        $cleanup = new ArticleMetaCleanupCommand;

        self::assertStringContainsString('seo:article-meta:audit', $audit->getName());
        self::assertStringContainsString('seo:article-meta:cleanup', $cleanup->getName());
    }

    public function test_preview_controller_and_analyzer_contract_methods_exist(): void
    {
        self::assertTrue(class_exists(ArticleSeoScorePreviewController::class));
        self::assertTrue(method_exists(SeoAnalyzerService::class, 'previewScoreContract'));
        self::assertTrue(method_exists(SeoAnalyzerService::class, 'analyzePreview'));
        self::assertTrue(method_exists(SeoAnalyzerService::class, 'analyzeSubmittedContent'));
    }

    public function test_persist_client_analysis_delegates_to_php_engine(): void
    {
        $source = file_get_contents(
            ProjectRoot::addonsPath().'/seo/src/Services/SeoAnalyzerService.php'
        );
        self::assertIsString($source);
        self::assertStringContainsString('function persistClientAnalysis', $source);
        self::assertMatchesRegularExpression(
            '/function persistClientAnalysis\([\s\S]*?return \$this->analyzeSubmittedContent/m',
            $source,
        );
    }

    public function test_editor_save_controller_runs_php_score_after_persist(): void
    {
        $source = file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Http/Controllers/ArticleEditorSyncController.php'
        );
        self::assertIsString($source);
        self::assertStringContainsString('analyzeSubmittedContent', $source);
        self::assertStringContainsString('seo-score/preview', file_get_contents(
            LegacyAddonPath::resolve('Providers/SeoPanelProvider.php')
        ) ?: '');
    }

    public function test_seo_meta_action_requeues_workspace_score_after_meta_change(): void
    {
        $source = file_get_contents(
            ProjectRoot::addonsPath().'/agent/src/Automation/Actions/Article/UpdateArticleSeoMetaAction.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString('SeoArticleScoringQueueService', $source);
        self::assertStringContainsString('dispatchForArticle($fresh, force: true)', $source);
        self::assertStringContainsString('\'seo_analysis_pending\' => $scoringQueued', $source);
        self::assertStringContainsString('\'seo_scoring_queued\' => $scoringQueued', $source);
        self::assertStringNotContainsString("'seo_analysis_pending' => false", $source);
    }

    public function test_js_preview_api_and_live_saved_labels_exist(): void
    {
        $api = file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleEditorApi.js'
        );
        $panel = file_get_contents(
            ProjectRoot::addonsPath().'/seo/resources/js/components/SeoScorePanel.jsx'
        );
        $i18n = file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/i18n.js'
        );

        self::assertIsString($api);
        self::assertStringContainsString('previewSeoScoreViaApi', $api);
        self::assertStringContainsString('seo-score/preview', $api);
        self::assertIsString($panel);
        self::assertStringContainsString('editor_seo_live_score_hint', $panel);
        self::assertStringContainsString('savedScore', $panel);
        self::assertIsString($i18n);
        self::assertStringContainsString('editor_seo_saved_score_label', $i18n);
    }

    public function test_parity_fixture_shared_rule_keys(): void
    {
        $fixturePath = dirname(__DIR__).'/Fixtures/seo_score_parity_preppy.json';
        self::assertFileExists($fixturePath);
        $fixture = json_decode((string) file_get_contents($fixturePath), true);
        self::assertIsArray($fixture);
        self::assertArrayHasKey('title', $fixture);
        self::assertArrayHasKey('expected_rule_keys', $fixture);
        self::assertSame(SeoScoringRulesRegistry::SCORE_VERSION, $fixture['score_version']);

        foreach ($fixture['expected_rule_keys'] as $key) {
            self::assertTrue(SeoScoringRulesRegistry::isKnownKey($key), "Unknown rule key [{$key}]");
        }
    }
}
