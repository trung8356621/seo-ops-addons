<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contract: shared link-preview pipeline (Topic + seed outputs).
 * Legacy comment components may remain on disk but are not primary UX.
 */
final class SeedingCommentLinkPreviewContractTest extends TestCase
{
    private function addonRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_shared_pipeline_module_exists(): void
    {
        $pipeline = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/services/linkPreviewPipeline.js'
        );
        self::assertStringContainsString('export function linksFromContent', $pipeline);
        self::assertStringContainsString('export function syncLinksWithText', $pipeline);
        self::assertStringContainsString('export async function ensureLinkPreviews', $pipeline);
        self::assertStringContainsString('export function buildCommentRecord', $pipeline);
        self::assertStringContainsString('export function hydrateLinksFromCache', $pipeline);
        self::assertStringContainsString('inflightByUrl', $pipeline);
        self::assertStringContainsString('fetchLinkPreview', $pipeline);
        self::assertStringContainsString('extractLinksFromPaste', $pipeline);
    }

    public function test_seed_output_and_topic_reuse_content_renderer(): void
    {
        $share = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/ShareGeneratePanel.jsx'
        );
        $card = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/TopicCard.jsx'
        );
        $hook = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/hooks/useEnsureLinkPreviews.js'
        );

        self::assertStringContainsString('ContentWithLinkPreviews', $share);
        self::assertStringContainsString('normalizeUrlKey', $share);
        self::assertStringContainsString('useEnsureLinkPreviews', $card);
        self::assertStringNotContainsString('fetchLinkPreview', $card);
        self::assertStringContainsString('ensureLinkPreviews', $hook);
        self::assertStringContainsString('hydrateLinksFromCache', $hook);
    }

    public function test_preview_card_has_topic_and_comment_variants(): void
    {
        $preview = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/LinkPreviewCard.jsx'
        );
        $rich = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/ContentWithLinkPreviews.jsx'
        );
        $css = (string) file_get_contents(
            $this->addonRoot().'/resources/css/seeding-workspace.css'
        );

        self::assertStringContainsString("variant = 'topic'", $preview);
        self::assertStringContainsString("variant === 'comment'", $preview);
        self::assertStringContainsString('data-preview-variant', $preview);
        self::assertStringContainsString('variant={variant}', $rich);
        self::assertStringContainsString('seeding-ws__link-preview--comment', $css);
        self::assertStringContainsString('grid-template-columns: 56px minmax(0, 1fr)', $css);
    }

    public function test_document_has_shared_link_previews_cache_v7(): void
    {
        $storage = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/services/storage.js'
        );
        self::assertStringContainsString('SCHEMA_VERSION = 8', $storage);
        self::assertStringContainsString('link_previews', $storage);
        self::assertStringContainsString('normalizeLinkPreviewCache', $storage);
        self::assertStringContainsString('function mergeLinksWithText', $storage);
        self::assertStringContainsString('seed_links', $storage);
    }

    public function test_workspace_wires_shared_cache_to_feed(): void
    {
        $workspace = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/SeedingWorkspace.jsx'
        );
        self::assertStringContainsString('linkPreviews', $workspace);
        self::assertStringContainsString('updateLinkPreviewCache', $workspace);
        self::assertStringContainsString('linkPreviewCache={linkPreviews}', $workspace);
        self::assertStringContainsString('onCacheUpdate={updateLinkPreviewCache}', $workspace);
    }

    public function test_rich_preview_caps_on_share_outputs(): void
    {
        $share = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/ShareGeneratePanel.jsx'
        );
        $rich = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/ContentWithLinkPreviews.jsx'
        );
        self::assertStringContainsString('maxRichPreviews={1}', $share);
        self::assertStringContainsString('maxRichPreviews', $rich);
        self::assertStringContainsString('seeding-ws__inline-url', $rich);
    }
}
