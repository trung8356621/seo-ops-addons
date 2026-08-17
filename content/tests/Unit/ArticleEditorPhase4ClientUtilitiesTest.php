<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4 static contracts: client outline, scheduler, no GET /outline on editor open.
 */
final class ArticleEditorPhase4ClientUtilitiesTest extends TestCase
{
    public function test_seo_article_editor_does_not_fetch_outline_on_open(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/SeoArticleEditor.jsx',
        );

        self::assertStringNotContainsString('seo-outline-rail-opened', $source);
        self::assertStringNotContainsString('seo-outline-interact', $source);
        self::assertStringNotContainsString('loadOutlineStatus', $source);
        self::assertStringContainsString('preferClientSource', $source);
        self::assertStringContainsString('buildClientOutlineTree', $source);
        self::assertStringContainsString('createArticleEditorUtilityScheduler', $source);
        self::assertStringContainsString('outlineHeadingFingerprint', $source);
        // Mutations may still call outlineApiRequest â€” but display path is client-first.
        self::assertStringContainsString("preferClientSource", $source);
    }

    public function test_outline_tab_skips_get_when_prefer_client_source(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleOutlineTab.jsx',
        );

        self::assertStringContainsString('preferClientSource = false', $source);
        self::assertStringContainsString('if (preferClientSource && !reextract)', $source);
        self::assertStringContainsString('clientOutline', $source);
    }

    public function test_client_outline_and_scheduler_modules_exist(): void
    {
        $base = ProjectRoot::addonsPath().'/content/resources/js/utils';
        self::assertFileExists($base.'/articleEditorClientOutline.js');
        self::assertFileExists($base.'/articleEditorUtilityScheduler.js');
        self::assertFileExists($base.'/articleEditorMetrics.js');

        $outline = (string) file_get_contents($base.'/articleEditorClientOutline.js');
        self::assertStringContainsString('export function buildClientOutlineTree', $outline);
        self::assertStringContainsString('h2, h3, h4', $outline);
        self::assertStringContainsString('client:', $outline);
        self::assertStringContainsString('extractOutlineHeadingsFromHtml', $outline);
        self::assertStringContainsString('heading_index', $outline);

        $scheduler = (string) file_get_contents($base.'/articleEditorUtilityScheduler.js');
        self::assertStringContainsString('cancelAll', $scheduler);
        self::assertStringContainsString('documentVersion', $scheduler);
        self::assertStringContainsString('AbortController', $scheduler);
    }

    public function test_append_outline_heading_no_longer_posts_on_section_add(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/hooks/useArticleEditorOutline.js',
        );
        $start = strpos($source, 'const appendOutlineHeadingForBlock = useCallback');
        self::assertNotFalse($start);
        $body = substr($source, $start, 1800);
        self::assertStringContainsString('client:', $body);
        self::assertStringNotContainsString("outlineApiRequest(articleId, ''", $body);
    }

    public function test_outline_heading_edit_is_local_first_without_server_id_gate(): void
    {
        $outlineTab = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleOutlineTab.jsx',
        );
        $outlineHook = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/hooks/useArticleEditorOutline.js',
        );
        $helpers = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/contentDocumentHelpers.js',
        );

        self::assertStringNotContainsString(
            'Heading chưa lưu xong trên server',
            $outlineTab,
        );
        self::assertStringContainsString('onSaveOutlineHeadingTitle', $outlineTab);
        self::assertStringContainsString('updateOutlineHeadingTitle', $outlineHook);
        self::assertStringContainsString('export function isPersistedOutlineHeadingId', $helpers);
        self::assertStringContainsString('resolveBlockIdFromOutlineHeadingId', $helpers);
        self::assertStringContainsString('client:', $helpers);
    }

    public function test_draft_schema_remains_html_canonical_v2(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleEditorStorage.js',
        );
        self::assertStringContainsString('ARTICLE_EDITOR_DRAFT_VERSION = 3', $source);
        self::assertStringContainsString('HTML canonical', $source);
        self::assertStringContainsString('export function hashContent', $source);
        self::assertStringContainsString('export function resolveLocalDraftDecision', $source);
        self::assertStringContainsString('export function writeSyncedLocalSnapshot', $source);
    }
}
