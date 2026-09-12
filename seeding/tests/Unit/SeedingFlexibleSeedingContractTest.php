<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Hybrid Seeding contracts — schema v8, author exclusion, copy-time link select.
 */
final class SeedingFlexibleSeedingContractTest extends TestCase
{
    private function addonRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function js(string $relative): string
    {
        return (string) file_get_contents($this->addonRoot().'/resources/js/seeding/'.$relative);
    }

    public function test_schema_v8_has_seed_links_batches_outputs_and_usage_cache(): void
    {
        $storage = $this->js('services/storage.js');
        self::assertStringContainsString('SCHEMA_VERSION = 8', $storage);
        self::assertStringContainsString('seed_links', $storage);
        self::assertStringContainsString('seed_batches', $storage);
        self::assertStringContainsString('seed_outputs', $storage);
        self::assertStringContainsString('link_usage_today', $storage);
        self::assertStringContainsString('requested_quantity', $storage);
        self::assertStringContainsString('generated_quantity', $storage);
    }

    public function test_can_seed_topic_excludes_author_and_drafts(): void
    {
        $auth = $this->js('features/workspace/auth.js');
        self::assertStringContainsString('export function canSeedTopic', $auth);
        self::assertStringContainsString('hasWorkspaceAccess', $auth);
        self::assertStringContainsString('archived', $auth);
        self::assertStringContainsString('opts.userId', $auth);
        self::assertStringContainsString('created_by_user_id', $auth);
        self::assertStringContainsString('canShareDraftTopic', $auth);
        self::assertStringNotContainsString('comments.length >= 1', $auth);
    }

    public function test_link_selector_weighted_eligible(): void
    {
        $selector = $this->js('services/linkSelector.js');
        self::assertStringContainsString('export function selectLinksForBatch', $selector);
        self::assertStringContainsString('eligibleLinks', $selector);
        self::assertStringContainsString('weightedPickByRemaining', $selector);
        self::assertStringContainsString('selectLinkForSingle', $selector);
    }

    public function test_copy_comment_selects_link_not_gen(): void
    {
        $copy = $this->js('services/copyComment.js');
        $gen = $this->js('services/seedGenerate.js');
        self::assertStringContainsString('buildCopyPayload', $copy);
        self::assertStringContainsString('eligibleLinks', $copy);
        self::assertStringContainsString('weightedPickByRemaining', $copy);
        self::assertStringNotContainsString('selectLinksForBatch', $gen);
        self::assertStringContainsString('selected_seed_link_id: null', $gen);
    }

    public function test_used_today_one_pass_aggregate(): void
    {
        $pool = $this->js('services/linkPool.js');
        self::assertStringContainsString('export function usedTodayByLinkId', $pool);
        self::assertStringContainsString('startOfLocalDay', $pool);
        self::assertStringContainsString('daily_limit', $pool);
        self::assertStringContainsString('linkPoolCapacity', $pool);
        self::assertStringContainsString('Đủ hôm nay', $pool);
    }

    public function test_seed_generate_adapts_legacy_comments_response(): void
    {
        $gen = $this->js('services/seedGenerate.js');
        self::assertStringContainsString('DEFAULT_SEED_QUANTITY = 3', $gen);
        self::assertStringContainsString('generateSampleComments', $gen);
        self::assertStringContainsString('data.comments', $gen);
        self::assertStringContainsString('requested_quantity', $gen);
        self::assertStringContainsString('generated_quantity', $gen);
        self::assertStringContainsString('regenerateSeedOutput', $gen);
        self::assertStringContainsString('updated_at', $gen);
    }

    public function test_workspace_primary_ux_has_no_claim_drawer(): void
    {
        $workspace = $this->js('SeedingWorkspace.jsx');
        $feed = $this->js('components/TopicFeed.jsx');
        self::assertStringContainsString('TopicFeed', $workspace);
        self::assertStringContainsString('activeGenTopicId', $workspace);
        self::assertStringContainsString('ShareGeneratePanel', $feed);
        self::assertStringNotContainsString('data-drawer="share-generate"', $workspace);
        self::assertStringContainsString('LinkPoolPanel', $workspace);
        self::assertStringContainsString('generateSeedBatch', $workspace);
        self::assertStringContainsString('canSeedTopic', $workspace);
        self::assertStringContainsString('Toaster', $workspace);
        self::assertStringNotContainsString('GlobalWorkDrawer', $workspace);
        self::assertStringNotContainsString('claimComment', $workspace);
        self::assertStringNotContainsString('FeedCommentsBlock', $workspace);
        self::assertStringNotContainsString('TopicCommentsSection', $workspace);
        self::assertStringNotContainsString('Cần ít nhất 1 bình luận', $workspace);
    }

    public function test_share_panel_gen_comment_and_append_checkbox(): void
    {
        $panel = $this->js('components/ShareGeneratePanel.jsx');
        self::assertStringContainsString('DEFAULT_SEED_QUANTITY', $panel);
        self::assertStringContainsString('Gen comment', $panel);
        self::assertStringContainsString('Append link khi Copy', $panel);
        self::assertStringContainsString('Link khả dụng', $panel);
        self::assertStringContainsString('Còn ngưỡng gợi ý', $panel);
        self::assertStringContainsString('Báo cáo', $panel);
        self::assertStringContainsString('Copy', $panel);
        self::assertStringNotContainsString('Nhận việc', $panel);
    }

    public function test_topic_card_chia_se_draft_gen_shared(): void
    {
        $card = $this->js('components/TopicCard.jsx');
        self::assertStringContainsString('Chia sẻ', $card);
        self::assertStringContainsString('Gen comment', $card);
        self::assertStringContainsString('canSeedTopic', $card);
        self::assertStringContainsString('canShareDraftTopic', $card);
        self::assertStringNotContainsString('FeedCommentsBlock', $card);
        self::assertStringNotContainsString('Nhận việc', $card);
        self::assertStringNotContainsString('Cần ít nhất 1 bình luận', $card);
    }

    public function test_personal_stats_not_fake_team(): void
    {
        $sidebar = $this->js('components/TeamStatsSidebar.jsx');
        $selectors = $this->js('features/workspace/selectors.js');
        self::assertStringContainsString('derivePersonalSeedingStats', $sidebar);
        self::assertStringContainsString('Seeding hôm nay', $sidebar);
        self::assertStringContainsString('Hoạt động của tôi', $sidebar);
        self::assertStringNotContainsString('Nhân viên', $sidebar);
        self::assertStringNotContainsString('Comment đang claim', $sidebar);
        self::assertStringContainsString('COUNT(outputs)', $selectors);
        self::assertStringContainsString('employees: []', $selectors);
    }

    public function test_recent_filter_and_link_pool_toolbar(): void
    {
        $selectors = $this->js('features/workspace/selectors.js');
        $toolbar = $this->js('components/FeedToolbar.jsx');
        self::assertStringContainsString('RECENT_SEEDED_DAYS', $selectors);
        self::assertStringContainsString('lastSeededAtForUser', $selectors);
        self::assertStringContainsString("filter === 'recent'", $selectors);
        self::assertStringContainsString('Đã dùng gần đây', $toolbar);
        self::assertStringContainsString('Link của tôi', $toolbar);
        self::assertStringContainsString('Tất cả', $toolbar);
    }

    public function test_php_prompt_is_seed_content_not_sample_comment(): void
    {
        $service = (string) file_get_contents(
            $this->addonRoot().'/src/Services/SeedingCommentGenerateService.php'
        );
        self::assertStringContainsString('nội dung seeding', $service);
        self::assertStringNotContainsString('bình luận mẫu', $service);
        $provider = (string) file_get_contents($this->addonRoot().'/src/SeedingServiceProvider.php');
        self::assertStringContainsString('comments/generate', $provider);
    }

    public function test_components_for_hybrid_seeding_exist(): void
    {
        $root = $this->addonRoot().'/resources/js/seeding';
        self::assertFileExists($root.'/services/linkPool.js');
        self::assertFileExists($root.'/services/linkSelector.js');
        self::assertFileExists($root.'/services/seedGenerate.js');
        self::assertFileExists($root.'/services/copyComment.js');
        self::assertFileExists($root.'/services/toast.js');
        self::assertFileExists($root.'/services/shareFeed.js');
        self::assertFileExists($root.'/components/LinkPoolPanel.jsx');
        self::assertFileExists($root.'/components/ShareGeneratePanel.jsx');
        self::assertFileExists($root.'/components/ReportModal.jsx');
    }

    public function test_topic_detail_has_no_comments_section(): void
    {
        $detail = $this->js('components/TopicDetail.jsx');
        self::assertStringContainsString('Gen comment', $detail);
        self::assertStringContainsString('canSeedTopic', $detail);
        self::assertStringContainsString('ResourceLinks', $detail);
        self::assertStringNotContainsString('TopicCommentsSection', $detail);
        self::assertStringNotContainsString('onClaim', $detail);
        self::assertStringNotContainsString('Cần ít nhất 1 bình luận', $detail);
    }
}
