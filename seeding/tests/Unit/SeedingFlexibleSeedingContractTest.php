<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Flexible Seeding contracts — schema v7, canSeedTopic, link selector, personal stats.
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

    public function test_schema_v7_has_seed_links_batches_outputs_and_no_comment_repair(): void
    {
        $storage = $this->js('services/storage.js');
        self::assertStringContainsString('SCHEMA_VERSION = 7', $storage);
        self::assertStringContainsString('seed_links', $storage);
        self::assertStringContainsString('seed_batches', $storage);
        self::assertStringContainsString('seed_outputs', $storage);
        self::assertStringContainsString('requested_quantity', $storage);
        self::assertStringContainsString('generated_quantity', $storage);
        self::assertStringContainsString('updated_at', $storage);
        self::assertStringContainsString('do not repair shared/completed', $storage);
        self::assertStringNotContainsString('Repair illegal state', $storage);
    }

    public function test_can_seed_topic_independent_of_author_and_can_mutate(): void
    {
        $auth = $this->js('features/workspace/auth.js');
        self::assertStringContainsString('export function canSeedTopic', $auth);
        self::assertStringContainsString('hasWorkspaceAccess', $auth);
        self::assertStringContainsString('archived', $auth);
        self::assertStringNotContainsString('comments.length >= 1', $auth);
        self::assertStringContainsString('NOT tied to topic author', $auth);
        // canSeedTopic body must not check created_by / canMutate
        $fnStart = strpos($auth, 'export function canSeedTopic');
        self::assertNotFalse($fnStart);
        $fn = substr($auth, $fnStart, 450);
        self::assertStringNotContainsString('created_by_user_id', $fn);
        self::assertStringNotContainsString('canMutate', $fn);
        self::assertStringNotContainsString('canEditTopic', $fn);
    }

    public function test_link_selector_provisional_weighted_soft_fallback(): void
    {
        $selector = $this->js('services/linkSelector.js');
        self::assertStringContainsString('export function selectLinksForBatch', $selector);
        self::assertStringContainsString('virtualUsed', $selector);
        self::assertStringContainsString('eligibleLinks', $selector);
        self::assertStringContainsString('weightedPickByRemaining', $selector);
        self::assertStringContainsString('soft fallback', $selector);
        self::assertStringContainsString('random', $selector);
        self::assertStringContainsString('activeLinks', $selector);
        self::assertStringContainsString('selectLinkForSingle', $selector);
    }

    public function test_used_today_one_pass_aggregate(): void
    {
        $pool = $this->js('services/linkPool.js');
        self::assertStringContainsString('export function usedTodayByLinkId', $pool);
        self::assertStringContainsString('startOfLocalDay', $pool);
        self::assertStringContainsString('daily_limit', $pool);
        self::assertStringContainsString('linkPoolCapacity', $pool);
        self::assertStringContainsString('Đủ hôm nay', $pool);
        self::assertStringNotContainsString('quota exceeded', strtolower($pool));
        self::assertStringNotContainsString('forbidden', strtolower($pool));
    }

    public function test_seed_generate_adapts_legacy_comments_response(): void
    {
        $gen = $this->js('services/seedGenerate.js');
        self::assertStringContainsString('DEFAULT_SEED_QUANTITY = 3', $gen);
        self::assertStringContainsString('generateSampleComments', $gen);
        self::assertStringContainsString('data.comments', $gen);
        self::assertStringContainsString('selectLinksForBatch', $gen);
        self::assertStringContainsString('requested_quantity', $gen);
        self::assertStringContainsString('generated_quantity', $gen);
        self::assertStringContainsString('regenerateSeedOutput', $gen);
        self::assertStringContainsString('updated_at', $gen);
        self::assertStringContainsString('rerandomLink', $gen);
    }

    public function test_workspace_primary_ux_has_no_claim_drawer_or_comment_gate(): void
    {
        $workspace = $this->js('SeedingWorkspace.jsx');
        self::assertStringContainsString('ShareGeneratePanel', $workspace);
        self::assertStringContainsString('LinkPoolPanel', $workspace);
        self::assertStringContainsString('generateSeedBatch', $workspace);
        self::assertStringContainsString('canSeedTopic', $workspace);
        self::assertStringNotContainsString('GlobalWorkDrawer', $workspace);
        self::assertStringNotContainsString('claimComment', $workspace);
        self::assertStringNotContainsString('completeWithProof', $workspace);
        self::assertStringNotContainsString('FeedCommentsBlock', $workspace);
        self::assertStringNotContainsString('TopicCommentsSection', $workspace);
        self::assertStringNotContainsString('Cần ít nhất 1 bình luận', $workspace);
        self::assertStringNotContainsString('Hoàn tất +1', $workspace);
        self::assertStringContainsString('Flexible Seeding', $workspace);
    }

    public function test_share_panel_quantity_default_and_soft_hints(): void
    {
        $panel = $this->js('components/ShareGeneratePanel.jsx');
        self::assertStringContainsString('DEFAULT_SEED_QUANTITY', $panel);
        self::assertStringContainsString('Chia sẻ', $panel);
        self::assertStringContainsString('Link khả dụng', $panel);
        self::assertStringContainsString('Còn ngưỡng gợi ý', $panel);
        self::assertStringContainsString('Bạn chưa có link trong Link Pool', $panel);
        self::assertStringContainsString('đã đạt ngưỡng hôm nay', $panel);
        self::assertStringNotContainsString('Đã chia sẻ', $panel);
        self::assertStringNotContainsString('Hoàn tất', $panel);
        self::assertStringNotContainsString('Nhận việc', $panel);
        self::assertStringContainsString('Gen lại', $panel);
        self::assertStringContainsString('Copy', $panel);
    }

    public function test_topic_card_chia_se_not_nhan_viec(): void
    {
        $card = $this->js('components/TopicCard.jsx');
        self::assertStringContainsString('Chia sẻ', $card);
        self::assertStringContainsString('canSeedTopic', $card);
        self::assertStringNotContainsString('FeedCommentsBlock', $card);
        self::assertStringNotContainsString('Nhận việc', $card);
        self::assertStringNotContainsString('Đẩy chia sẻ', $card);
        self::assertStringNotContainsString('Cần ít nhất 1 bình luận', $card);
        self::assertStringContainsString('isTopicTrending', $card);
    }

    public function test_personal_stats_not_fake_team(): void
    {
        $sidebar = $this->js('components/TeamStatsSidebar.jsx');
        $selectors = $this->js('features/workspace/selectors.js');
        self::assertStringContainsString('derivePersonalSeedingStats', $sidebar);
        self::assertStringContainsString('Seeding hôm nay', $sidebar);
        self::assertStringContainsString('Hoạt động của tôi', $sidebar);
        self::assertStringContainsString('Lượt Gen', $sidebar);
        self::assertStringContainsString('Nội dung đã tạo', $sidebar);
        self::assertStringNotContainsString('Nhân viên', $sidebar);
        self::assertStringNotContainsString('Comment đang claim', $sidebar);
        self::assertStringNotContainsString('Bình luận', $sidebar);
        self::assertStringContainsString('COUNT(outputs)', $selectors);
        self::assertStringContainsString('local-only', $selectors);
        self::assertStringContainsString('employees: []', $selectors);
    }

    public function test_recent_filter_current_user_batches(): void
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

    public function test_components_for_flexible_seeding_exist(): void
    {
        $root = $this->addonRoot().'/resources/js/seeding';
        self::assertFileExists($root.'/services/linkPool.js');
        self::assertFileExists($root.'/services/linkSelector.js');
        self::assertFileExists($root.'/services/seedGenerate.js');
        self::assertFileExists($root.'/components/LinkPoolPanel.jsx');
        self::assertFileExists($root.'/components/ShareGeneratePanel.jsx');
    }

    public function test_topic_detail_has_no_comments_section(): void
    {
        $detail = $this->js('components/TopicDetail.jsx');
        self::assertStringContainsString('Chia sẻ', $detail);
        self::assertStringContainsString('canSeedTopic', $detail);
        self::assertStringContainsString('ResourceLinks', $detail);
        self::assertStringNotContainsString('TopicCommentsSection', $detail);
        self::assertStringNotContainsString('onClaim', $detail);
        self::assertStringNotContainsString('Cần ít nhất 1 bình luận', $detail);
    }
}
