<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\Content\Http\Controllers\ArticleEditorSessionController;
use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionService;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Content\Support\ArticleWordPressPostType;
use Illuminate\Database\Eloquent\Collection;
use ReflectionMethod;
use Tests\Support\ProjectRoot;
use Tests\TestCase;

/**
 * Document-noop must not skip independent editor-bundle metadata (post_type, title, …).
 * Mix of source contracts + in-memory classification (no MySQL required).
 */
final class ArticleEditorDocumentNoopMetadataRegressionTest extends TestCase
{
    public function test_controller_distinguishes_document_noop_from_whole_save_noop(): void
    {
        $doc = $this->methodSource(new ReflectionMethod(ArticleEditorSessionController::class, 'document'));
        $session = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleEditor/ArticleEditorSessionService.php',
        );

        self::assertStringContainsString('tryDocumentNoopAck', $session);
        self::assertStringContainsString('bundleApply->apply', $doc);
        self::assertStringContainsString('document_noop', $doc);
        self::assertStringContainsString('metadata_noop', $doc);
        self::assertStringContainsString('metadataChanged', $doc);
        self::assertStringContainsString('savePatch->build', $doc);
        self::assertStringContainsString("\$payload['noop'] ?? false) === true && ! \$metadataChanged", $doc);
        self::assertDoesNotMatchRegularExpression(
            '/if \(\(\$payload\[\'noop\'\].*=== true\) \{\s*return response\(\)->json\(\[\s*\.\.\.\$payload,/s',
            $doc,
        );
    }

    public function test_bundle_apply_owns_post_type_and_core_metadata_helpers(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleEditorBundleApplyService.php',
        );

        self::assertStringContainsString('applyCoreArticleFields', $source);
        self::assertStringContainsString('persistArticlePostTypeMeta', $source);
        self::assertStringContainsString('ArticleWordPressPostType::classificationForEditor', $source);
        self::assertStringContainsString('$unchanged =', $source);
        self::assertStringContainsString('markLocalEditPending', $source);
        self::assertStringContainsString('faqsMatchPersisted', $source);
        self::assertStringContainsString('productAlbumMatchesPersisted', $source);
        self::assertMatchesRegularExpression('/function apply\([^)]*\): bool/', $source);
        self::assertStringNotContainsString('SeoProjectTask::normalizePostType', $source);
    }

    public function test_save_context_and_patch_keep_raw_wp_post_type_boundary(): void
    {
        $context = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Support/ArticleEditorSaveContext.php',
        );
        $patch = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleEditorSavePatchService.php',
        );

        self::assertStringContainsString('ArticleWordPressPostType::normalizeEditorInput', $context);
        self::assertStringContainsString("\$bundle['meta']", $context);
        self::assertStringContainsString('ArticleWordPressPostType::resolve($article)', $patch);
        self::assertStringNotContainsString('SeoProjectTask::normalizePostType', $patch);
    }

    public function test_document_noop_ack_still_skips_body_revision_work(): void
    {
        $noop = $this->methodSource(new ReflectionMethod(
            ArticleEditorSessionService::class,
            'tryDocumentNoopAck',
        ));
        self::assertStringContainsString("'noop' => true", $noop);
        self::assertStringNotContainsString('writeArticleRow', $noop);
        self::assertStringNotContainsString('captureAfterSave', $noop);
        self::assertStringNotContainsString('prepareCanonicalDocument', $noop);
    }

    public function test_lost_ack_reconciles_stale_version_without_body_write(): void
    {
        $noop = $this->methodSource(new ReflectionMethod(
            ArticleEditorSessionService::class,
            'tryDocumentNoopAck',
        ));
        self::assertStringContainsString('expectedVersion > $currentVersion', $noop);
        self::assertStringContainsString('$reconciled = true', $noop);
        self::assertStringContainsString("'reconciled' => \$reconciled", $noop);
        self::assertStringNotContainsString('captureAfterSave', $noop);
    }

    public function test_content_project_task_post_type_helper_untouched(): void
    {
        self::assertSame('post', SeoProjectTask::normalizePostType('page'));
        self::assertSame('page', ArticleWordPressPostType::normalizeEditorInput('page'));
        self::assertSame('product', ArticleWordPressPostType::normalizeEditorInput('product'));
    }

    public function test_post_to_product_classification_persists_raw_and_canonical(): void
    {
        $article = $this->article([
            'content_type' => 'post',
            'wp_is_term' => '0',
            'wp_post_type' => 'post',
        ]);
        $classification = ArticleWordPressPostType::classificationForEditor($article, 'product');

        self::assertSame(ContentType::Product, $classification['content_type']);
        self::assertSame('product', $classification['wp_post_type']);
        self::assertFalse($classification['wp_is_term']);
    }

    public function test_post_to_page_and_product_to_post_classification(): void
    {
        $post = $this->article([
            'content_type' => 'post',
            'wp_is_term' => '0',
            'wp_post_type' => 'post',
        ]);
        $page = ArticleWordPressPostType::classificationForEditor($post, 'page');
        self::assertSame(ContentType::Page, $page['content_type']);
        self::assertSame('page', $page['wp_post_type']);

        $product = $this->article([
            'content_type' => 'product',
            'wp_is_term' => '0',
            'wp_post_type' => 'product',
        ]);
        $back = ArticleWordPressPostType::classificationForEditor($product, 'post');
        self::assertSame(ContentType::Post, $back['content_type']);
        self::assertSame('post', $back['wp_post_type']);
    }

    public function test_custom_native_cpt_noop_preserves_slug(): void
    {
        $article = $this->article([
            'content_type' => 'post',
            'wp_is_term' => '0',
            'wp_post_type' => 'recipe',
        ]);
        $article->setAttribute('wp_post_id', 501);

        self::assertSame('recipe', ArticleWordPressPostType::resolve($article));
        $classification = ArticleWordPressPostType::classificationForEditor($article, 'recipe');
        self::assertSame('recipe', $classification['wp_post_type']);
    }

    public function test_audited_independent_metadata_fields_are_in_bundle_apply_or_core(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleEditorBundleApplyService.php',
        );

        foreach ([
            'applyCoreArticleFields', // title/slug/status/schedule
            'persistSeoMetaFields',
            'persistArticlePostTypeMeta',
            'applyCategories',
            'persistFeaturedImage',
            'persistProductAlbum',
            'faqEditor->saveFromEditor',
            'faqsMatchPersisted',
        ] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
    }

    public function test_patch_response_uses_resolved_server_post_type(): void
    {
        $patch = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleEditorSavePatchService.php',
        );
        $doc = $this->methodSource(new ReflectionMethod(ArticleEditorSessionController::class, 'document'));

        self::assertStringContainsString('ArticleWordPressPostType::resolve($article)', $patch);
        self::assertStringContainsString("fresh(['articleMetas'])", $doc);
        self::assertStringContainsString('savePatch->build($fresh', $doc);
    }

    /**
     * @param  array<string, string>  $meta
     */
    private function article(array $meta): SeoArticle
    {
        $article = new SeoArticle;
        $article->setRelation(
            'articleMetas',
            new Collection(array_map(
                static function (string $key, string $value): ArticleMeta {
                    $row = new ArticleMeta;
                    $row->forceFill([
                        'meta_key' => $key,
                        'meta_value' => $value,
                    ]);

                    return $row;
                },
                array_keys($meta),
                array_values($meta),
            )),
        );

        return $article;
    }

    private function methodSource(ReflectionMethod $method): string
    {
        $file = (string) $method->getFileName();
        $start = (int) $method->getStartLine();
        $end = (int) $method->getEndLine();
        $lines = file($file);
        if ($lines === false) {
            return '';
        }

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
