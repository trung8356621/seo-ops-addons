<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * React-first hybrid contracts — toast, commit points, no Livewire primary UX.
 */
final class SeedingReactFirstContractTest extends TestCase
{
    private function addonRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function js(string $relative): string
    {
        return (string) file_get_contents($this->addonRoot().'/resources/js/seeding/'.$relative);
    }

    public function test_sonner_toast_mounted_not_filament(): void
    {
        $workspace = $this->js('SeedingWorkspace.jsx');
        $toast = $this->js('services/toast.js');
        $pkg = (string) file_get_contents($this->addonRoot().'/package.json');

        self::assertStringContainsString('"sonner"', $pkg);
        self::assertStringContainsString("from 'sonner'", $toast);
        self::assertStringContainsString('notifySuccess', $toast);
        self::assertStringContainsString('<Toaster', $workspace);
        self::assertStringContainsString("from 'sonner'", $workspace);
        self::assertStringContainsString('Outside grid', $workspace);
        self::assertStringNotContainsString('Notification::make', $workspace);
        self::assertStringNotContainsString('session()->flash', $workspace);
        self::assertStringNotContainsString('wire:click', $workspace);
        self::assertStringNotContainsString('wire:model', $workspace);
    }

    public function test_primary_shell_only_wire_ignore(): void
    {
        $blade = (string) file_get_contents(
            $this->addonRoot().'/resources/views/filament/pages/seeding-topics-page.blade.php'
        );
        self::assertStringContainsString('wire:ignore', $blade);
        self::assertStringContainsString('seeding-workspace-root', $blade);
        self::assertStringNotContainsString('wire:click', $blade);
        self::assertStringNotContainsString('wire:model', $blade);
    }

    public function test_share_optimistic_rollback_helpers(): void
    {
        $share = $this->js('services/shareFeed.js');
        self::assertStringContainsString('removeDraftTopic', $share);
        self::assertStringContainsString('restoreDraftTopic', $share);
        self::assertStringContainsString('mergeSharedFeed', $share);
        self::assertStringContainsString('applyReportSuccessLocal', $share);
    }

    public function test_copy_is_client_side_with_append_at_copy(): void
    {
        $copy = $this->js('services/copyComment.js');
        $gen = $this->js('services/seedGenerate.js');
        $panel = $this->js('components/ShareGeneratePanel.jsx');

        self::assertStringContainsString('buildCopyPayload', $copy);
        self::assertStringContainsString('writeClipboard', $copy);
        self::assertStringContainsString('navigator.clipboard.writeText', $copy);
        self::assertStringContainsString('softLimitReached', $copy);
        self::assertStringNotContainsString('seedingApiFetch', $copy);
        self::assertStringNotContainsString('selectLinksForBatch', $gen);
        self::assertStringContainsString('selected_seed_link_id: null', $gen);
        self::assertStringContainsString('Append link khi Copy', $panel);
        self::assertStringContainsString('buildCopyPayload', $panel);
        self::assertStringContainsString('Báo cáo', $panel);
        self::assertStringContainsString('Gen comment', $panel);
    }

    public function test_author_cannot_seed_own_topic(): void
    {
        $auth = $this->js('features/workspace/auth.js');
        self::assertStringContainsString('created_by_user_id', $auth);
        self::assertStringContainsString('opts.userId', $auth);
        self::assertStringContainsString("state === 'draft'", $auth);
        self::assertStringContainsString('canShareDraftTopic', $auth);
    }

    public function test_workspace_commit_points_and_scoped_loading(): void
    {
        $workspace = $this->js('SeedingWorkspace.jsx');
        self::assertStringContainsString('shareTopicApi', $workspace);
        self::assertStringContainsString('submitReport', $workspace);
        self::assertStringContainsString('fetchSharedFeed', $workspace);
        self::assertStringContainsString('ReportModal', $workspace);
        self::assertStringContainsString('setGenerating(true)', $workspace);
        self::assertStringContainsString('setSharingTopicKey', $workspace);
        self::assertStringContainsString('setReporting(true)', $workspace);
        self::assertStringNotContainsString('seeding-ws__global-loading', $workspace);
        self::assertStringNotContainsString('window.location.reload', $workspace);
    }

    public function test_api_routes_registered(): void
    {
        $provider = (string) file_get_contents(
            $this->addonRoot().'/src/SeedingServiceProvider.php'
        );
        self::assertStringContainsString("'/feed'", $provider);
        self::assertStringContainsString('topics/share', $provider);
        self::assertStringContainsString("'/reports'", $provider);
        self::assertStringContainsString('SeedingFeedController', $provider);
        self::assertStringContainsString('SeedingShareTopicController', $provider);
        self::assertStringContainsString('SeedingReportController', $provider);
    }

    public function test_migrations_exist_on_omi_seeding(): void
    {
        $root = $this->addonRoot().'/database/migrations';
        self::assertFileExists($root.'/2026_09_09_100000_create_seeding_topics_table.php');
        self::assertFileExists($root.'/2026_09_09_100100_create_seeding_reports_table.php');
        $topics = (string) file_get_contents($root.'/2026_09_09_100000_create_seeding_topics_table.php');
        self::assertStringContainsString("omi_seeding", $topics);
        self::assertStringContainsString('required_comments_per_user', $topics);
        self::assertStringContainsString('member_count_at_share', $topics);
        self::assertStringContainsString('max_comments_target', $topics);
    }
}
