<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Frontend UX contracts for feed refactor (source-level; localStorage SoT).
 */
final class SeedingFeedUxContractTest extends TestCase
{
    private function addonRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_no_duplicate_title_from_auto_copy(): void
    {
        $selectors = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/features/workspace/selectors.js'
        );
        $content = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/features/workspace/content.js'
        );
        $card = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/TopicCard.jsx'
        );

        self::assertStringContainsString('topicDistinctTitle', $selectors);
        self::assertStringContainsString('isAutoCopiedTitle', $content);
        self::assertStringContainsString('topicDistinctTitle(topic)', $card);
        self::assertStringNotContainsString('topicCardTitle(topic)', $card);
    }

    public function test_content_replaces_url_with_preview(): void
    {
        $content = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/features/workspace/content.js'
        );
        $rich = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/ContentWithLinkPreviews.jsx'
        );

        self::assertStringContainsString('splitContentByUrls', $content);
        self::assertStringContainsString('hasRichPreview', $content);
        self::assertStringContainsString('LinkPreviewCard', $rich);
        self::assertStringContainsString('hasRichPreview(meta)', $rich);
        self::assertStringContainsString('findLinkMeta', $rich);
    }

    public function test_feed_comment_preview_limited(): void
    {
        $content = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/features/workspace/content.js'
        );
        $feedComments = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/FeedCommentsBlock.jsx'
        );
        self::assertStringContainsString('latestCommentsPreview', $content);
        self::assertStringContainsString('previewLimit = 2', $feedComments);
        self::assertStringContainsString('Xem thêm', $feedComments);
    }

    public function test_auth_helpers_author_vs_non_author(): void
    {
        $auth = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/features/workspace/auth.js'
        );
        self::assertStringContainsString('canEditTopic', $auth);
        self::assertStringContainsString('canDeleteTopic', $auth);
        self::assertStringContainsString('canEditComment', $auth);
        self::assertStringContainsString('canDeleteComment', $auth);
        self::assertStringContainsString('canShareTopic', $auth);
        self::assertStringContainsString('created_by_user_id', $auth);
        self::assertStringContainsString('author_user_id', $auth);
        self::assertStringContainsString('comments.length >= 1', $auth);
    }

    public function test_sidebar_is_module_shell_not_edit_page(): void
    {
        $workspace = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/SeedingWorkspace.jsx'
        );
        $sidebar = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/TopicContextSidebar.jsx'
        );
        $composer = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/TopicComposer.jsx'
        );
        $css = (string) file_get_contents(
            $this->addonRoot().'/resources/css/seeding-workspace.css'
        );

        self::assertStringContainsString('TopicContextSidebar', $workspace);
        self::assertStringContainsString('data-layout="shell"', $workspace);
        self::assertStringContainsString('selected_topic_id', $workspace);
        self::assertStringContainsString('data-sidebar="topic-context"', $sidebar);
        self::assertStringNotContainsString('TopicContextSidebar', $composer);
        self::assertStringContainsString('seeding-ws--shell', $css);
        self::assertStringContainsString('@container (min-width: 1100px)', $css);
        self::assertStringContainsString('repeat(3, minmax(0, 1fr))', $css);
        self::assertStringContainsString('seeding-ws__feed-grid', $css);
        self::assertDoesNotMatchRegularExpression(
            '/seeding-ws__feed-grid[^{]*\{[^}]*repeat\(\s*4/s',
            $css
        );
    }

    public function test_share_eligibility_reused(): void
    {
        $detail = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/TopicDetail.jsx'
        );
        $card = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/TopicCard.jsx'
        );
        self::assertStringContainsString('canShareTopic', $detail);
        self::assertStringContainsString('shareStatusOf', $card);
        self::assertStringContainsString('Cần ít nhất 1 bình luận', $card);
    }

    public function test_vertical_grid_and_new_components_exist(): void
    {
        $root = $this->addonRoot().'/resources/js/seeding/components';
        foreach ([
            'TopicContextSidebar.jsx',
            'FeedCommentsBlock.jsx',
            'LinkPreviewCard.jsx',
            'ContentWithLinkPreviews.jsx',
            'TopicCard.jsx',
            'TopicFeed.jsx',
        ] as $file) {
            self::assertFileExists($root.'/'.$file);
        }
        $feed = (string) file_get_contents($root.'/TopicFeed.jsx');
        self::assertStringContainsString('seeding-ws__feed-grid', $feed);
    }

    public function test_storage_tracks_ownership_and_preview_fields(): void
    {
        $storage = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/services/storage.js'
        );
        self::assertStringContainsString('created_by_user_id', $storage);
        self::assertStringContainsString('author_user_id', $storage);
        self::assertStringContainsString('preview_image_url', $storage);
        self::assertStringContainsString('preview_fetched_at', $storage);
        self::assertStringContainsString('selected_topic_id', $storage);
        self::assertStringContainsString('sidebar_collapsed', $storage);
    }
}
