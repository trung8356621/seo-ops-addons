<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\WordPress\Services\WordPressManualSyncService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Contract: Media Health â‰  SEO analysis; Content Project hides/blocks Manual Sync WP.
 */
final class ArticleEditorMediaHealthSyncContractTest extends TestCase
{
    public function test_images_media_health_ignores_seo_ratio_for_error_status(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/resources/js/utils/assistantWidgetHealth.js',
        );
        $imagesFn = $this->extractBetween(
            $source,
            'export function buildImagesWidgetHealth({',
            'export function buildSeoWidgetHealth({',
        );
        $analyzeFn = $this->extractBetween(
            $source,
            'export function analyzeImageRowsHealth(rows, keyword = \'\') {',
            'export function buildImagesWidgetHealth({',
        );

        self::assertStringContainsString('rowHasLocalPlaceholderSlug', $source);
        self::assertStringNotContainsString('rowHasUnresolvedMediaSlug', $source);
        self::assertStringContainsString('error_count', $imagesFn);
        self::assertStringContainsString('warning_count', $imagesFn);
        self::assertStringContainsString('info_count', $imagesFn);
        self::assertStringContainsString("severity: 'info'", $imagesFn);
        self::assertStringContainsString('image_ratio_low', $imagesFn);
        self::assertStringContainsString("status = 'error'", $imagesFn);
        self::assertStringContainsString("status = 'warning'", $imagesFn);
        self::assertStringContainsString("status = 'info'", $imagesFn);
        // SEO ratio / recommendation must not inflate integrity error status.
        self::assertStringNotContainsString(
            'fixableIssues + (missingRecommended > 0 ? 1 : 0)',
            $source,
        );
        self::assertStringContainsString(
            'WP filename â‰  keyword is NOT a hard error',
            $analyzeFn,
        );
        self::assertStringContainsString('image_slug_unresolved', $analyzeFn);
        self::assertStringContainsString('isWordPressProtectedMedia', $analyzeFn);
        self::assertStringContainsString('image_reference_invalid', $analyzeFn);
        self::assertStringNotContainsString('isImageReadyForWpSlugFix', $source);
        self::assertStringNotContainsString('const issueCount = fixableIssues;', $source);
    }

    public function test_featured_presence_clears_featured_missing_hard_error(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/resources/js/utils/assistantWidgetHealth.js',
        );
        $fn = $this->extractBetween(
            $source,
            'export function buildFeaturedWidgetHealth({',
            'export function buildGalleryWidgetHealth({',
        );

        self::assertStringContainsString('featured_missing', $fn);
        self::assertStringContainsString('hardErrors', $fn);
        self::assertStringContainsString('issue_count: hardErrors.length', $fn);
        self::assertStringNotContainsString('featured_alt_missing', $fn);
        self::assertStringNotContainsString('featured_slug_not_fixed', $fn);
        self::assertStringNotContainsString('image_ratio_low', $fn);
        self::assertStringContainsString("status = 'success'", $fn);
    }

    public function test_slug_and_featured_mutations_bump_media_health_tick(): void
    {
        $editor = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/SeoArticleEditor.jsx',
        );

        self::assertStringContainsString('setMediaHealthTick', $editor);
        self::assertStringContainsString('seo-assistant-widget-health-refresh', $editor);
        self::assertStringContainsString('article-editor-media-snapshot-changed', $editor);
        self::assertStringContainsString('finalizeSlugRenameSideEffects', $editor);
        self::assertStringContainsString('setFeaturedHealthSnapshot(loadFeaturedImage(articleId))', $editor);
        // Phase-1 perf: typing must not rebuild featured/gallery health builders.
        self::assertStringContainsString('publishPartialRuntimeWidgetHealth', $editor);
        self::assertStringContainsString('Content widgets only', $editor);
        self::assertStringContainsString('Media snapshot widgets', $editor);
    }

    public function test_manual_sync_api_blocked_for_content_project(): void
    {
        $source = $this->methodSource(
            new ReflectionMethod(WordPressManualSyncService::class, 'enqueueFromEditorBundle'),
        );

        self::assertStringContainsString('belongsToContentProject', $source);
        self::assertStringContainsString('content_project_manual_sync_forbidden', $source);
        self::assertStringContainsString('return $this->blocked(', $source);
        $projectBranch = $this->extractBetween(
            $source,
            'if ($this->contentProjectMembership->belongsToContentProject($article)) {',
            '$bundle = $this->syncQueue->applyPublishImmediatelyToBundle($bundle);',
        );
        self::assertStringNotContainsString('enqueueManual', $projectBranch);
        self::assertStringNotContainsString('ManualWordPressSyncJob', $projectBranch);
    }

    public function test_independent_article_keeps_sync_wp_button(): void
    {
        $actions = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/article-resource/pages/partials/article-editor-page-actions.blade.php'),
        );

        self::assertStringContainsString('@else', $actions);
        self::assertStringContainsString('data-seo-page-action="sync"', $actions);
        self::assertStringContainsString('data-seo-sync-mode="wordpress_sync"', $actions);
        self::assertStringContainsString('data-seo-page-action="save-close"', $actions);
        self::assertStringContainsString('articleIsInContentProject', $actions);
        self::assertStringContainsString('$contentProjectWpSyncEligible', $actions);
    }

    public function test_sync_wp_payload_carries_current_media_snapshot(): void
    {
        $api = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleEditorApi.js',
        );

        self::assertStringContainsString("import { getMediaSnapshot } from './articleEditorMediaSnapshot.js';", $api);
        self::assertStringContainsString('const mediaSnapshot = articleId > 0 ? getMediaSnapshot(articleId) : null;', $api);
        self::assertStringContainsString('featured_image: featured', $api);
        self::assertStringContainsString('product_album: productAlbum', $api);
        self::assertStringContainsString('media_snapshot: mediaSnapshot', $api);
        self::assertStringNotContainsString('featured_image: null', $api);
        self::assertStringNotContainsString('product_album: null', $api);
    }

    public function test_sync_wp_flushes_pending_featured_mutation_before_payload_build(): void
    {
        $entry = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/article-editor.jsx',
        );
        $snapshot = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleEditorMediaSnapshot.js',
        );

        self::assertStringContainsString('flushMediaSnapshotMutations', $entry);
        $syncPath = $this->extractBetween(
            $entry,
            "if (normalizedAction === 'sync' && typeof window.__seoCollectEditorHeavyBundle === 'function') {",
            'const apiPayload = buildArticleEditorApiPayload(editorBundle, wire);',
        );
        self::assertStringContainsString('await flushMediaSnapshotMutations(articleId);', $syncPath);
        self::assertStringContainsString('export async function flushMediaSnapshotMutations', $snapshot);
        self::assertStringContainsString("mediaSnapshotMutationKey(id, 'featured')", $snapshot);
        self::assertStringContainsString("mediaSnapshotMutationKey(id, 'gallery')", $snapshot);
        self::assertStringContainsString('await Promise.all(pending);', $snapshot);
    }

    public function test_bundle_apply_persists_featured_from_media_snapshot_for_sync_wp(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleEditorBundleApplyService.php',
        );
        $apply = $this->extractBetween(
            $source,
            'public function apply(SeoArticle $article, array $bundle, ArticleEditorSaveContext $context): void',
            'public function applySeoMetaOnly',
        );

        self::assertStringContainsString('$bundle[\'featured_image\'] ?? $this->featuredImageFromMediaSnapshot($bundle)', $apply);
        self::assertStringContainsString('$bundle[\'product_album\'] ?? $this->productAlbumFromMediaSnapshot($bundle)', $apply);
        self::assertStringContainsString('$this->persistFeaturedImage($article, $context, $featuredImage);', $apply);
        self::assertStringContainsString('$this->persistProductAlbum($article, $context, $productAlbum);', $apply);
        self::assertStringContainsString('private function featuredImageFromMediaSnapshot', $source);
        self::assertStringContainsString('private function normalizeMediaSnapshotItem', $source);
    }

    public function test_manual_sync_persists_bundle_then_refreshes_article_before_enqueue(): void
    {
        $source = $this->methodSource(
            new ReflectionMethod(WordPressManualSyncService::class, 'enqueueFromEditorBundle'),
        );

        self::assertStringContainsString('$this->bundleApply->apply($article, $bundle, $context);', $source);
        self::assertStringContainsString('$persist = $this->actions->dispatch(', $source);
        self::assertStringContainsString('$article = $fresh->fresh() ?? $fresh;', $source);
        self::assertStringContainsString('return $this->enqueueManual(', $source);
    }

    public function test_wordpress_sync_finalize_pushes_pending_featured_media_and_surfaces_failure(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/wordpress/src/Services/WordPressArticleSyncService.php',
        );
        $complete = $this->extractBetween(
            $source,
            'public function completeEditorSyncResponse(SeoArticle $article, array $prepared, array $decoded, array $syncOptions = []): array',
            'private function storeEditorSyncFingerprint',
        );

        self::assertStringContainsString('pushPendingMediaToWordPress($article->fresh())', $complete);
        self::assertStringContainsString('featured/album=ok', $complete);
        self::assertStringContainsString('áº¢nh chÆ°a Ä‘áº©y Ä‘Æ°á»£c:', $complete);
        self::assertStringContainsString("'success' => false", $complete);
        self::assertStringContainsString("'error_code' => 'featured_media_sync_failed'", $complete);
        self::assertStringContainsString("'failed_stage' => 'featured_media_push'", $complete);
    }

    public function test_archived_content_project_membership_is_not_active_ownership(): void
    {
        $membership = (string) file_get_contents(
            (new ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArticleMembership::class,
            ))->getFileName(),
        );

        self::assertStringContainsString('function belongsToContentProject', $membership);
        self::assertStringContainsString('assignedTaskForArticle', $membership);
        self::assertStringContainsString('historicalAssignedTaskForArticle', $membership);
        self::assertStringContainsString('belongsToActiveContentProject', $membership);

        $assigned = $this->extractBetween(
            $membership,
            'public function assignedTaskForArticle',
            'public function historicalAssignedTaskForArticle',
        );
        self::assertStringContainsString('activeTaskForArticle', $assigned);

        $belongs = $this->extractBetween(
            $membership,
            'public function belongsToContentProject',
            'public function activeProjectForArticle',
        );
        self::assertStringContainsString('belongsToActiveContentProject', $belongs);
    }

    private function methodSource(ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }

    private function extractBetween(string $haystack, string $start, string $end): string
    {
        $from = strpos($haystack, $start);
        self::assertNotFalse($from, 'start marker missing: '.$start);
        $to = strpos($haystack, $end, $from);
        self::assertNotFalse($to, 'end marker missing: '.$end);

        return substr($haystack, $from, $to - $from);
    }
}
