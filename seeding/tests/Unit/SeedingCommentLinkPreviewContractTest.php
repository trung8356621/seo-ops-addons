<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contract: Comment reuses Topic link-preview pipeline (no duplicate impl).
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

    public function test_comment_create_edit_gen_use_build_comment_record(): void
    {
        $feed = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/FeedCommentsBlock.jsx'
        );
        $detail = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/TopicCommentsSection.jsx'
        );

        foreach ([$feed, $detail] as $src) {
            self::assertStringContainsString('buildCommentRecord', $src);
            self::assertStringContainsString('CommentRichBody', $src);
            self::assertStringContainsString("source: 'ai'", $src);
            self::assertStringContainsString("source: 'manual'", $src);
            self::assertStringContainsString('canEditComment', $src);
            self::assertStringContainsString('canDeleteComment', $src);
        }
    }

    public function test_comment_rich_body_reuses_content_renderer_and_hook(): void
    {
        $body = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/CommentRichBody.jsx'
        );
        $hook = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/hooks/useEnsureLinkPreviews.js'
        );
        $card = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/TopicCard.jsx'
        );

        self::assertStringContainsString('ContentWithLinkPreviews', $body);
        self::assertStringContainsString('useEnsureLinkPreviews', $body);
        self::assertStringContainsString("variant = 'comment'", $body);
        self::assertStringContainsString('maxRichPreviews = 1', $body);
        self::assertStringContainsString('syncLinksWithText', $body);

        self::assertStringContainsString('ensureLinkPreviews', $hook);
        self::assertStringContainsString('hydrateLinksFromCache', $hook);

        // Topic card uses same hook — no private fetch loop.
        self::assertStringContainsString('useEnsureLinkPreviews', $card);
        self::assertStringNotContainsString('fetchLinkPreview', $card);
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

    public function test_document_has_shared_link_previews_cache(): void
    {
        $storage = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/services/storage.js'
        );
        self::assertStringContainsString('SCHEMA_VERSION = 6', $storage);
        self::assertStringContainsString('link_previews', $storage);
        self::assertStringContainsString('normalizeLinkPreviewCache', $storage);
        self::assertStringContainsString('function mergeLinksWithText', $storage);
        self::assertStringContainsString('links,', $storage);
    }

    public function test_workspace_wires_shared_cache_to_feed_and_detail(): void
    {
        $workspace = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/SeedingWorkspace.jsx'
        );
        self::assertStringContainsString('linkPreviews', $workspace);
        self::assertStringContainsString('updateLinkPreviewCache', $workspace);
        self::assertStringContainsString('linkPreviewCache={linkPreviews}', $workspace);
        self::assertStringContainsString('onCacheUpdate={updateLinkPreviewCache}', $workspace);
    }

    public function test_multiple_link_feed_caps_rich_preview(): void
    {
        $feed = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/FeedCommentsBlock.jsx'
        );
        $rich = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/ContentWithLinkPreviews.jsx'
        );
        self::assertStringContainsString('maxRichPreviews={1}', $feed);
        self::assertStringContainsString('maxRichPreviews', $rich);
        self::assertStringContainsString('seeding-ws__inline-url', $rich);
    }
}
