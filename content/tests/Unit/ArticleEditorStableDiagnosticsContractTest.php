<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class ArticleEditorStableDiagnosticsContractTest extends TestCase
{
    private function js(string $relative): string
    {
        return ProjectRoot::addonsPath().'/content/resources/js/'.$relative;
    }

    public function test_health_store_keeps_last_stable_snapshot_api(): void
    {
        $store = (string) file_get_contents($this->js('editor/runtime/editorRuntimeHealthStore.js'));
        self::assertStringContainsString('bindDiagnosticsArticleScope', $store);
        self::assertStringContainsString('beginDiagnosticsRefresh', $store);
        self::assertStringContainsString('markDiagnosticsRefreshing', $store);
        self::assertStringContainsString('getDiagnosticsGeneration', $store);
        self::assertStringContainsString('refresh_status', $store);
        self::assertStringContainsString('article-editor-save-started', $store);
        // Must NOT sticky-preserve cleared issues as "incomplete".
        self::assertStringNotContainsString('isEmptyResetHealth', $store);
        self::assertStringNotContainsString('prevIssues > 0 && nextIssues === 0', $store);
    }

    public function test_compose_rejects_incomplete_widget_inputs(): void
    {
        $compose = (string) file_get_contents($this->js('editor/runtime/composeRuntimeWidgetHealth.js'));
        self::assertStringContainsString('detectIncompleteWidgets', $compose);
        self::assertStringContainsString('markDiagnosticsRefreshing', $compose);
        self::assertStringContainsString('buildPublishingWidgetHealth', $compose);
        self::assertStringContainsString('generation', $compose);
        // Incomplete must refresh-only (no fake zero/error diagnostic payloads).
        self::assertStringContainsString('markDiagnosticsRefreshing(incompleteIds)', $compose);
        self::assertStringNotContainsString('Keep last stable — mark refreshing via patch meta', $compose);
    }

    public function test_nav_chip_does_not_inherit_links_health_for_cta(): void
    {
        $nav = (string) file_get_contents($this->js('editor/host/EditorSidebarNavigation.jsx'));
        self::assertStringContainsString('resolveChipHealth', $nav);
        self::assertStringContainsString('Never inherit Links validation severity', $nav);
        self::assertStringNotContainsString("chip.id === 'cta' ? healthMap?.links", $nav);
    }

    public function test_publishing_taxonomy_resolver_is_post_type_aware(): void
    {
        $resolver = (string) file_get_contents($this->js('utils/publishingTaxonomyResolver.js'));
        self::assertStringContainsString("raw === 'page'", $resolver);
        self::assertStringContainsString('product_category', $resolver);
        self::assertStringContainsString('__seoResolvePublishCategoryRequirement', $resolver);

        $health = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/resources/js/utils/assistantWidgetHealth.js',
        );
        self::assertStringContainsString('buildPublishingWidgetHealth', $health);
        self::assertStringContainsString('publishing_category_missing', $health);
        self::assertStringContainsString('Chưa chọn danh mục', $health);
    }

    public function test_editor_publishes_publishing_health_and_stable_meta(): void
    {
        $editor = (string) file_get_contents($this->js('components/SeoArticleEditor.jsx'));
        self::assertStringContainsString('seo-publishing-categories-changed', $editor);
        self::assertStringContainsString('buildPublishingWidgetHealth', $editor);
        self::assertStringContainsString('preserveStable: true', $editor);
        self::assertStringContainsString('bindDiagnosticsArticleScope', $editor);
    }

    public function test_save_queue_emits_save_started_for_diagnostics_refresh(): void
    {
        $queue = (string) file_get_contents($this->js('utils/articleEditorSaveQueue.js'));
        self::assertStringContainsString('article-editor-save-started', $queue);
    }
}
