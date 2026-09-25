<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Frontend UX contracts for hybrid React-first feed.
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

    public function test_auth_helpers_seed_vs_edit(): void
    {
        $auth = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/features/workspace/auth.js'
        );
        $workspace = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/SeedingWorkspace.jsx'
        );
        self::assertStringContainsString('canEditTopic', $auth);
        self::assertStringContainsString('canDeleteTopic', $auth);
        self::assertStringContainsString('canSeedTopic', $auth);
        self::assertStringContainsString('canShareDraftTopic', $auth);
        self::assertStringContainsString('created_by_user_id', $auth);
        self::assertStringContainsString('hasWorkspaceAccess', $auth);
        self::assertStringContainsString('seeding.seeder', $auth);
        self::assertStringContainsString('seedingRole', $auth);
        self::assertStringNotContainsString('comments.length >= 1', $auth);

        // Exact Seeder must not get blanket Link Pool CRUD from hasWorkspaceAccess alone.
        self::assertMatchesRegularExpression(
            '/export function canManageOwnSeedLinks[\s\S]*seeding\.seeder[\s\S]*return false/m',
            $auth,
        );
        self::assertStringNotContainsString(
            "export function canManageOwnSeedLinks(hasWorkspaceAccess = true) {\n    return hasWorkspaceAccess !== false;\n}",
            $auth,
        );
        self::assertStringContainsString('canManageLinkPool', $workspace);
        self::assertStringNotContainsString('Link Pool', $workspace);
        self::assertStringNotContainsString('AssignedLinksEditor', (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/TopicComposer.jsx'
        ));
        self::assertStringContainsString('Danh sách link', (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/FeedToolbar.jsx'
        ));
        self::assertStringNotContainsString('Link của tôi', (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/FeedToolbar.jsx'
        ));
    }

    public function test_sidebar_is_stable_personal_stats_without_active_topic(): void
    {
        $workspace = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/SeedingWorkspace.jsx'
        );
        $sidebar = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/SeedingSidebar.jsx'
        );
        $composer = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/TopicComposer.jsx'
        );
        $card = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/TopicCard.jsx'
        );
        $selectors = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/features/workspace/selectors.js'
        );
        $css = (string) file_get_contents(
            $this->addonRoot().'/resources/css/seeding-workspace.css'
        );
        $panel = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/ShareGeneratePanel.jsx'
        );

        self::assertStringContainsString('SeedingSidebar', $workspace);
        self::assertStringContainsString('data-layout="shell"', $workspace);
        self::assertStringNotContainsString('activeTopic={genTopic}', $workspace);
        self::assertStringNotContainsString('Mở panel', $workspace);
        self::assertStringNotContainsString('TopicContextSidebar', $workspace);
        self::assertStringNotContainsString('TopicContextSidebar', $composer);

        self::assertFileExists($this->addonRoot().'/resources/js/seeding/components/SeedingSidebar.jsx');
        self::assertFileExists($this->addonRoot().'/resources/js/seeding/components/TopicWorkTargets.jsx');
        self::assertFileExists($this->addonRoot().'/resources/js/seeding/components/AssignedLinksEditor.jsx');
        self::assertStringContainsString('data-sidebar="personal-stats"', $sidebar);
        self::assertStringContainsString('data-sidebar-reopen', $sidebar);
        self::assertStringContainsString('Seeding hôm nay', $sidebar);
        self::assertStringContainsString('derivePersonalSeedingStats', $sidebar);
        self::assertStringContainsString('Quản lý danh sách link', $sidebar);
        self::assertStringContainsString('data-shared-assignments', $sidebar);
        self::assertStringContainsString('sharedAssignments', $sidebar);
        self::assertStringNotContainsString('Quản lý Link Pool', $sidebar);
        self::assertStringNotContainsString('Link cá nhân (tuỳ chọn)', $sidebar);
        self::assertStringNotContainsString('Topic hiện tại', $sidebar);
        self::assertStringNotContainsString('activeTopic', $sidebar);
        self::assertStringNotContainsString('deriveActiveTopicLinkTargets', $sidebar);
        self::assertStringNotContainsString('data-stats="active-topic"', $sidebar);
        self::assertStringNotContainsString('Chi tiết chủ đề', $sidebar);
        self::assertStringNotContainsString('FeedCommentsBlock', $sidebar);

        self::assertStringContainsString('TopicWorkTargets', $card);
        self::assertStringContainsString('data-topic-social', (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/TopicWorkTargets.jsx'
        ));
        self::assertStringNotContainsString('data-topic-assigned-links', (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/TopicWorkTargets.jsx'
        ));
        self::assertStringNotContainsString('AssignedLinksEditor', $composer);
        self::assertStringContainsString('Chưa có link shared assignment', $panel);
        self::assertStringNotContainsString('Bạn chưa có link trong Link Pool.', $panel);

        self::assertStringContainsString('export function derivePersonalSeedingStats', $selectors);
        self::assertStringContainsString('export function deriveSharedAssignmentRows', $selectors);
        self::assertStringContainsString('export function deriveTopicSocialTarget', $selectors);
        self::assertStringContainsString('shortenUrlDisplay', $selectors);
        self::assertStringContainsString('genBatches', $selectors);
        self::assertStringContainsString('daily_link_progress', (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/services/storage.js'
        ));
        self::assertStringContainsString('target_per_day', (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/services/storage.js'
        ));

        self::assertStringContainsString('seeding-ws--shell', $css);
        self::assertStringContainsString('seeding-ws__feed-host', $css);
        self::assertStringContainsString('seeding-ws__feed-grid', $css);
        self::assertStringContainsString('is-seeder-quick-feed', $css);
        self::assertStringContainsString('seeding-ws__topic-work', $css);
        self::assertMatchesRegularExpression(
            '/(?:^|\n)\.seeding-ws__feed-grid\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\)/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.seeding-ws__feed-host\.is-seeder-quick-feed\s+\.seeding-ws__feed-grid\s*\{[^}]*repeat\(\s*2/s',
            $css
        );
        self::assertStringNotContainsString(
            '.seeding-ws__feed-item.is-gen-open {\n    grid-column: 1 / -1;',
            $css
        );
        self::assertStringNotContainsString(
            '.seeding-ws__feed-host.is-seeder-quick-feed .seeding-ws__feed-item.is-gen-open {\n    grid-column: 1 / -1;',
            $css
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.seeding-ws__feed-item\.is-gen-open\s*\{[^}]*grid-column:\s*1\s*\/\s*-1/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.seeding-ws__vcard\.is-gen-open|\.seeding-ws__feed-item\.is-gen-open\s*>\s*\.seeding-ws__vcard/s',
            $css
        );
        self::assertStringContainsString('box-shadow: 0 0 0 2px rgba(234, 88, 12', $css);
        self::assertStringNotContainsString('@container seeding-feed', $css);
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

    public function test_card_uses_draft_share_and_gen(): void
    {
        $detail = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/TopicDetail.jsx'
        );
        $card = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/components/TopicCard.jsx'
        );
        self::assertStringContainsString('canSeedTopic', $detail);
        self::assertStringContainsString('canSeedTopic', $card);
        self::assertStringContainsString('Chia sẻ', $card);
        self::assertStringContainsString('Gen comment', $card);
        self::assertStringNotContainsString('Cần ít nhất 1 bình luận', $card);
        self::assertStringNotContainsString('onSelect', $card);
        self::assertStringNotContainsString('is-selected', $card);
    }

    public function test_vertical_grid_and_new_components_exist(): void
    {
        $root = $this->addonRoot().'/resources/js/seeding/components';
        foreach ([
            'SeedingSidebar.jsx',
            'TeamStatsSidebar.jsx',
            'LinkPreviewCard.jsx',
            'ContentWithLinkPreviews.jsx',
            'TopicCard.jsx',
            'TopicFeed.jsx',
            'SeederQuickFeed.jsx',
            'TopicWorkTargets.jsx',
            'AssignedLinksEditor.jsx',
            'ShareGeneratePanel.jsx',
            'LinkPoolPanel.jsx',
            'ReportModal.jsx',
        ] as $file) {
            self::assertFileExists($root.'/'.$file);
        }
        $feed = (string) file_get_contents($root.'/TopicFeed.jsx');
        $quick = (string) file_get_contents($root.'/SeederQuickFeed.jsx');
        self::assertStringContainsString('seeding-ws__feed-grid', $feed);
        self::assertStringContainsString('seeding-ws__feed-host', $feed);
        self::assertStringContainsString('ShareGeneratePanel', $feed);
        self::assertStringContainsString('activeGenTopicId', $feed);
        self::assertStringContainsString('is-gen-open', $feed);
        self::assertStringContainsString('dailyProgressMap', $feed);
        self::assertStringContainsString('dailyLinkProgress', $feed);
        self::assertStringNotContainsString('selectedId', $feed);
        self::assertStringNotContainsString('onSelect', $feed);
        self::assertStringNotContainsString('data-drawer="share-generate"', $feed);
        self::assertStringContainsString('is-seeder-quick-feed', $quick);
        self::assertStringContainsString('isWebsiteShareActionable', $quick);
        self::assertStringContainsString('WebsiteShareCard', $quick);
        self::assertStringContainsString('dailyProgressMap', $quick);
        $css = (string) file_get_contents($this->addonRoot().'/resources/css/seeding-workspace.css');
        self::assertDoesNotMatchRegularExpression(
            '/\.seeding-ws__feed-item\.is-gen-open\s*\{[^}]*grid-column:\s*1\s*\/\s*-1/s',
            $css
        );
    }

    public function test_website_share_uses_platform_snapshot_badges_without_remove_or_quantity_controls(): void
    {
        $root = $this->addonRoot().'/resources/js/seeding/components';
        $card = (string) file_get_contents($root.'/WebsiteShareCard.jsx');
        $feed = (string) file_get_contents($root.'/WebsiteShareFeed.jsx');

        self::assertStringContainsString('Website Share', $card);
        self::assertStringContainsString('job.targets', $card);
        self::assertStringContainsString('is_complete', $card);
        self::assertStringContainsString('Chưa cấu hình Social Account active cho domain này.', $card);
        self::assertStringContainsString('Báo cáo', $card);
        self::assertStringNotContainsString('completed_count}/{t.target_count', $card);
        self::assertStringNotContainsString('×', $card);
        self::assertStringNotContainsString('remove', $card);
        self::assertStringNotContainsString('skip', $card);
        self::assertStringContainsString('writeClipboard', $feed);
        self::assertStringNotContainsString('navigator.clipboard.writeText', $feed);
    }

    public function test_storage_tracks_ownership_and_preview_fields(): void
    {
        $storage = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/services/storage.js'
        );
        self::assertStringContainsString('created_by_user_id', $storage);
        self::assertStringContainsString('preview_image_url', $storage);
        self::assertStringContainsString('preview_fetched_at', $storage);
        self::assertStringContainsString('sidebar_collapsed', $storage);
        self::assertStringContainsString('link_previews', $storage);
        self::assertStringContainsString('seed_links', $storage);
        self::assertStringContainsString('link_usage_today', $storage);
        self::assertStringContainsString('daily_link_progress', $storage);
        self::assertStringContainsString('target_per_day', $storage);
        self::assertStringNotContainsString('selected_topic_id', $storage);
    }
}
