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
        self::assertStringContainsString('maxRichPreviews', $rich);
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

    public function test_sidebar_is_team_stats_not_topic_detail(): void
    {
        $workspace = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/SeedingWorkspace.jsx'
        );
        $sidebar = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/TeamStatsSidebar.jsx'
        );
        $composer = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/TopicComposer.jsx'
        );
        $selectors = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/features/workspace/selectors.js'
        );
        $css = (string) file_get_contents(
            $this->addonRoot().'/resources/css/seeding-workspace.css'
        );

        self::assertStringContainsString('TeamStatsSidebar', $workspace);
        self::assertStringContainsString('data-layout="shell"', $workspace);
        self::assertStringNotContainsString('TopicContextSidebar', $workspace);
        self::assertStringNotContainsString('selectedId', $workspace);
        self::assertStringNotContainsString('selected_topic_id', $workspace);
        self::assertStringNotContainsString('TopicContextSidebar', $composer);

        self::assertFileExists($this->addonRoot().'/resources/js/seeding/components/TeamStatsSidebar.jsx');
        self::assertFileDoesNotExist($this->addonRoot().'/resources/js/seeding/components/TopicContextSidebar.jsx');
        self::assertStringContainsString('data-sidebar="team-stats"', $sidebar);
        self::assertStringContainsString('deriveTeamStats', $sidebar);
        self::assertStringContainsString('Thống kê hôm nay', $sidebar);
        self::assertStringContainsString('Nhân viên', $sidebar);
        self::assertStringContainsString('Xem báo cáo', $sidebar);
        self::assertStringNotContainsString('Chi tiết chủ đề', $sidebar);
        self::assertStringNotContainsString('FeedCommentsBlock', $sidebar);
        self::assertStringNotContainsString('LinkPreviewCard', $sidebar);
        self::assertStringNotContainsString('onSelect', $sidebar);

        self::assertStringContainsString('export function deriveTeamStats', $selectors);
        self::assertStringContainsString('commentsCreated', $selectors);
        self::assertStringContainsString('completed_at', $selectors);

        self::assertStringContainsString('seeding-ws--shell', $css);
        self::assertStringContainsString('seeding-ws__feed-host', $css);
        self::assertStringContainsString('seeding-ws__feed-grid', $css);
        self::assertStringContainsString('@container seeding-feed (min-width: 620px)', $css);
        self::assertStringContainsString('@container seeding-feed (min-width: 980px)', $css);
        self::assertStringContainsString('repeat(2, minmax(0, 1fr))', $css);
        self::assertStringContainsString('repeat(3, minmax(0, 1fr))', $css);
        self::assertDoesNotMatchRegularExpression(
            '/seeding-ws__feed-grid[^{]*\{[^}]*repeat\(\s*4/s',
            $css
        );
        self::assertStringNotContainsString('minmax(min(100%, 320px)', $css);
    }

    public function test_preview_media_is_bounded(): void
    {
        $css = (string) file_get_contents(
            $this->addonRoot().'/resources/css/seeding-workspace.css'
        );
        $preview = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/LinkPreviewCard.jsx'
        );

        self::assertStringContainsString('data-preview-media', $preview);
        self::assertStringContainsString('aspect-ratio: 16 / 9', $css);
        self::assertStringContainsString('max-height: 180px', $css);
        self::assertStringContainsString('object-fit: cover', $css);
        self::assertStringContainsString('position: absolute', $css);
        self::assertStringContainsString('seeding-ws__link-preview-img', $css);
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
        self::assertStringNotContainsString('onSelect', $card);
        self::assertStringNotContainsString('is-selected', $card);
    }

    public function test_vertical_grid_and_new_components_exist(): void
    {
        $root = $this->addonRoot().'/resources/js/seeding/components';
        foreach ([
            'TeamStatsSidebar.jsx',
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
        self::assertStringContainsString('seeding-ws__feed-host', $feed);
        self::assertStringNotContainsString('selectedId', $feed);
        self::assertStringNotContainsString('onSelect', $feed);
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
        self::assertStringContainsString('sidebar_collapsed', $storage);
        self::assertStringNotContainsString('selected_topic_id', $storage);
    }
}
