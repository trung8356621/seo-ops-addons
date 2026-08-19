<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewSeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ApproveProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ArchiveProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\BlockProjectItemGenerationCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\GenerateProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RerunProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RerunProjectItemStepCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SendToPublishingQueueCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\StartReviewCommand;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionCatalog;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionsPresenter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectItemActionCatalogTest extends TestCase
{
    public function test_canonical_labels_are_shared_for_single_and_bulk(): void
    {
        $en = LegacyAddonPath::read('lang/en/filament.php');
        foreach ([
            "'item_action_run_generation' => 'Run generation'",
            "'item_action_rerun_outline' => 'Rerun from Outline'",
            "'item_action_rerun_writing' => 'Rerun from Writing'",
            "'item_action_skip_generation' => 'Skip generation'",
            "'item_action_archive' => 'Archive'",
            "'item_action_view_details' => 'View details'",
            "'item_action_start_review' => 'Start review'",
            "'item_action_approve' => 'Approve'",
        ] as $line) {
            self::assertStringContainsString($line, $en);
        }

        foreach (ContentProjectItemActionCatalog::definitions() as $def) {
            if (! $def['single'] || ! $def['bulk']) {
                continue;
            }
            self::assertNotSame('', $def['label_key']);
        }
    }

    public function test_group_order_is_identical_for_single_and_bulk(): void
    {
        self::assertSame(
            ['content', 'review', 'publishing_queue', 'lifecycle', 'other'],
            ContentProjectItemActionCatalog::groupOrder(),
        );
        self::assertSame(
            ['Content', 'Review', 'Publishing Queue', 'Lifecycle', 'Other'],
            array_values(ContentProjectItemActionCatalog::groupHeadings()),
        );

        $singleGroups = [];
        foreach (ContentProjectItemActionCatalog::singleDefinitions() as $def) {
            $singleGroups[] = $def['group'];
        }
        $bulkGroups = [];
        foreach (ContentProjectItemActionCatalog::bulkDefinitions() as $def) {
            $bulkGroups[] = $def['group'];
        }

        $order = ContentProjectItemActionCatalog::groupOrder();
        $lastSingle = -1;
        foreach ($singleGroups as $group) {
            $idx = array_search($group, $order, true);
            self::assertNotFalse($idx);
            self::assertGreaterThanOrEqual($lastSingle, (int) $idx);
            $lastSingle = (int) $idx;
        }
        $lastBulk = -1;
        foreach ($bulkGroups as $group) {
            $idx = array_search($group, $order, true);
            self::assertNotFalse($idx);
            self::assertGreaterThanOrEqual($lastBulk, (int) $idx);
            $lastBulk = (int) $idx;
        }

        $menu = LegacyAddonPath::read('resources/views/components/content-project-item-actions-menu.blade.php');
        $contentPos = strpos($menu, '>Content</p>');
        $reviewPos = strpos($menu, '>Review</p>');
        $pqPos = strpos($menu, '>Publishing Queue</p>');
        $lifePos = strpos($menu, '>Lifecycle</p>');
        $otherPos = strpos($menu, '>Other</p>');
        self::assertNotFalse($contentPos);
        self::assertNotFalse($reviewPos);
        self::assertNotFalse($pqPos);
        self::assertNotFalse($lifePos);
        self::assertNotFalse($otherPos);
        self::assertLessThan($reviewPos, $contentPos);
        self::assertLessThan($pqPos, $reviewPos);
        self::assertLessThan($lifePos, $pqPos);
        self::assertLessThan($otherPos, $lifePos);
    }

    public function test_same_semantic_action_maps_to_same_backend_family(): void
    {
        $outline = ContentProjectItemActionCatalog::definition('rerun_outline');
        self::assertNotNull($outline);
        self::assertSame('regenOutline', $outline['single_method']);
        self::assertSame('bulkRegenOutline', $outline['bulk_method']);
        self::assertSame([RerunProjectItemStepCommand::class], $outline['command_family']);

        $writing = ContentProjectItemActionCatalog::definition('rerun_writing');
        self::assertNotNull($writing);
        self::assertSame('regenArticle', $writing['single_method']);
        self::assertSame('bulkRegenArticle', $writing['bulk_method']);
        self::assertSame([RerunProjectItemStepCommand::class], $writing['command_family']);

        $archive = ContentProjectItemActionCatalog::definition('archive');
        self::assertNotNull($archive);
        self::assertSame('archiveOne', $archive['single_method']);
        self::assertSame('archiveSelected', $archive['bulk_method']);
        self::assertSame([ArchiveProjectItemsCommand::class], $archive['command_family']);

        $review = ContentProjectItemActionCatalog::definition('start_review');
        self::assertNotNull($review);
        self::assertSame('startReviewOne', $review['single_method']);
        self::assertSame('startReviewSelected', $review['bulk_method']);
        self::assertSame([StartReviewCommand::class], $review['command_family']);

        $approve = ContentProjectItemActionCatalog::definition('approve');
        self::assertNotNull($approve);
        self::assertSame('approveOne', $approve['single_method']);
        self::assertSame('approveSelected', $approve['bulk_method']);
        self::assertSame([ApproveProjectItemsCommand::class], $approve['command_family']);

        $queue = ContentProjectItemActionCatalog::definition('send_publishing_queue');
        self::assertNotNull($queue);
        self::assertSame('sendToPublishingQueueOne', $queue['single_method']);
        self::assertSame('bulkSendToPublishingQueue', $queue['bulk_method']);
        self::assertSame([SendToPublishingQueueCommand::class], $queue['command_family']);

        $skip = ContentProjectItemActionCatalog::definition('skip_generation');
        self::assertNotNull($skip);
        self::assertSame('skipGenerationOne', $skip['single_method']);
        self::assertSame('skipGenerationSelected', $skip['bulk_method']);
        self::assertSame([BlockProjectItemGenerationCommand::class], $skip['command_family']);

        $run = ContentProjectItemActionCatalog::definition('run_generation');
        self::assertNotNull($run);
        self::assertSame('createOrRerunOne', $run['single_method']);
        self::assertSame('generateSelected', $run['bulk_method']);
        self::assertContains(GenerateProjectItemsCommand::class, $run['command_family']);
        self::assertContains(RerunProjectItemsCommand::class, $run['command_family']);

        $page = (string) file_get_contents((new ReflectionClass(ViewSeoProject::class))->getFileName());
        self::assertStringContainsString('function generateSelected', $page);
        self::assertStringContainsString('dispatchGenerate', $page);
        self::assertStringContainsString('function createOrRerunOne', $page);
        self::assertStringContainsString('function skipGenerationSelected', $page);
        self::assertStringNotContainsString('createOrRerunOne($id)', $page);
        self::assertStringNotContainsString('BulkGenerateNewCommand', $page);
    }

    public function test_single_only_actions_are_absent_from_bulk_menu(): void
    {
        foreach (['open_article', 'view_details', 'regen_image'] as $key) {
            $def = ContentProjectItemActionCatalog::definition($key);
            self::assertNotNull($def);
            self::assertTrue($def['single']);
            self::assertFalse($def['bulk']);
        }

        $bulkKeys = array_map(
            static fn (array $def): string => $def['key'],
            ContentProjectItemActionCatalog::bulkDefinitions(),
        );
        self::assertNotContains('open_article', $bulkKeys);
        self::assertNotContains('view_details', $bulkKeys);
        self::assertNotContains('regen_image', $bulkKeys);

        $toolbar = LegacyAddonPath::read('resources/views/components/content-project-bulk-selection-toolbar.blade.php');
        self::assertStringNotContainsString('open_article', $toolbar);
        self::assertStringNotContainsString('openExecutionDetails', $toolbar);
        self::assertStringNotContainsString('item_action_open_article', $toolbar);
        self::assertStringNotContainsString('item_action_view_details', $toolbar);
        self::assertStringNotContainsString('item_action_regen_image', $toolbar);
    }

    public function test_permissions_share_presenter_flags_for_single_and_bulk(): void
    {
        $reviewRow = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'review',
            'queue_status' => 'none',
            'generation_badge' => ['key' => 'success'],
            'can_generate' => false,
            'can_regen' => true,
            'article_edit_url' => '/a/1',
        ]);
        $draftRow = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'draft',
            'queue_status' => 'none',
            'generation_badge' => ['key' => 'pending'],
            'generation_status' => 'pending',
            'is_generate_pending_runnable' => true,
            'can_generate' => true,
            'can_regen' => false,
            'article_edit_url' => null,
        ]);

        $approve = ContentProjectItemActionCatalog::definition('approve');
        self::assertSame('approve', $approve['presenter_flag']);
        self::assertSame('approve', $approve['bulk_presenter_flag']);
        self::assertTrue($reviewRow['approve']);
        self::assertFalse($draftRow['approve']);

        $toolbar = LegacyAddonPath::read('resources/views/components/content-project-bulk-selection-toolbar.blade.php');
        self::assertStringContainsString('canManageContentProjectWorkflow', $toolbar);
        $menu = LegacyAddonPath::read('resources/views/components/content-project-item-actions-menu.blade.php');
        self::assertStringContainsString('ContentProjectItemActionsPresenter::forRow', $menu);
    }

    public function test_eligibility_blocks_bulk_when_single_would_hide_action(): void
    {
        $review = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'review',
            'queue_status' => 'none',
            'generation_badge' => ['key' => 'success'],
            'can_generate' => false,
            'can_regen' => true,
            'article_edit_url' => '/a/1',
        ]);
        $draft = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'draft',
            'queue_status' => 'none',
            'generation_badge' => ['key' => 'pending'],
            'generation_status' => 'pending',
            'is_generate_pending_runnable' => true,
            'can_generate' => true,
            'can_regen' => false,
            'article_edit_url' => null,
        ]);

        self::assertTrue($review['approve']);
        self::assertFalse($draft['approve']);

        $summaries = ContentProjectItemActionCatalog::summarizeBulk(2, [
            1 => $review,
            2 => $draft,
        ]);
        $approve = $this->summaryByKey($summaries, 'approve');
        self::assertSame('partial', $approve['state']);
        self::assertFalse($approve['enabled']);
        self::assertSame(1, $approve['eligible']);
    }

    public function test_mixed_selection_does_not_enable_partial_approve(): void
    {
        $flags = [];
        for ($i = 1; $i <= 10; $i++) {
            $flags[$i] = ContentProjectItemActionsPresenter::forRow([
                'lifecycle' => $i <= 6 ? 'review' : 'draft',
                'queue_status' => 'none',
                'generation_badge' => ['key' => 'success'],
                'can_generate' => false,
                'can_regen' => true,
                'article_edit_url' => '/a/'.$i,
            ]);
        }

        $summaries = ContentProjectItemActionCatalog::summarizeBulk(10, $flags);
        $approve = $this->summaryByKey($summaries, 'approve');
        self::assertSame(6, $approve['eligible']);
        self::assertSame(10, $approve['total']);
        self::assertSame('partial', $approve['state']);
        self::assertFalse($approve['enabled']);

        $allReview = ContentProjectItemActionCatalog::summarizeBulk(2, [
            1 => $flags[1],
            2 => $flags[2],
        ]);
        $allApprove = $this->summaryByKey($allReview, 'approve');
        self::assertSame('all', $allApprove['state']);
        self::assertTrue($allApprove['enabled']);

        $toolbar = LegacyAddonPath::read('resources/views/components/content-project-bulk-selection-toolbar.blade.php');
        self::assertStringContainsString('item_action_partial_eligible', $toolbar);
        self::assertStringContainsString('item_action_partial_tooltip', $toolbar);
        self::assertStringContainsString("\$enabled = ! empty(\$action['enabled'])", $toolbar);
        self::assertStringContainsString('disabled', $toolbar);
    }

    public function test_bulk_toolbar_has_one_dispatch_path_per_action(): void
    {
        $toolbar = LegacyAddonPath::read('resources/views/components/content-project-bulk-selection-toolbar.blade.php');
        self::assertSame(1, substr_count($toolbar, 'wire:click="{{ $action[\'bulk_method\'] }}"'));
        self::assertStringNotContainsString('wire:click="generateSelected"', $toolbar);
        self::assertStringNotContainsString('$wire.generateSelected', $toolbar);
        self::assertStringNotContainsString('$wire.bulkRegenOutline', $toolbar);
        self::assertStringNotContainsString('Generate working items', $toolbar);
        self::assertStringNotContainsString('Regen outline', $toolbar);
        self::assertStringNotContainsString('Archive selected', $toolbar);
        self::assertStringContainsString('beginRowProcessing', $toolbar);
        self::assertStringContainsString('item_actions_menu', $toolbar);

        $page = (string) file_get_contents((new ReflectionClass(ViewSeoProject::class))->getFileName());
        self::assertSame(1, substr_count($page, 'function generateSelected'));
        self::assertSame(1, substr_count($page, 'function skipGenerationSelected'));
        self::assertSame(1, substr_count($page, 'function bulkRegenOutline'));
        self::assertSame(1, substr_count($page, 'function archiveSelected'));
    }

    public function test_row_and_bulk_share_icons(): void
    {
        $menu = LegacyAddonPath::read('resources/views/components/content-project-item-actions-menu.blade.php');
        foreach (ContentProjectItemActionCatalog::definitions() as $def) {
            if ($def['icon'] === '') {
                continue;
            }
            self::assertStringContainsString($def['icon'], $menu);
        }
    }

    public function test_run_generation_bulk_is_generate_pending_only(): void
    {
        $create = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'draft',
            'queue_status' => 'none',
            'generation_badge' => ['key' => 'pending'],
            'generation_status' => 'pending',
            'is_generate_pending_runnable' => true,
            'can_generate' => true,
            'can_regen' => false,
            'article_edit_url' => null,
        ]);
        $rerun = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'draft',
            'queue_status' => 'none',
            'generation_badge' => ['key' => 'failed'],
            'generation_status' => 'failed',
            'can_generate' => true,
            'can_regen' => true,
            'article_edit_url' => '/a/1',
        ]);

        self::assertTrue($create['create_or_rerun']);
        self::assertTrue($create['run_generation_bulk']);
        self::assertTrue($rerun['create_or_rerun']);
        self::assertFalse($rerun['run_generation_bulk']);

        $mixed = ContentProjectItemActionCatalog::summarizeBulk(2, [
            1 => $create,
            2 => $rerun,
        ]);
        $run = $this->summaryByKey($mixed, 'run_generation');
        self::assertSame('partial', $run['state']);
        self::assertFalse($run['enabled']);
        self::assertSame(1, $run['eligible']);
    }

    /**
     * @param  list<array<string, mixed>>  $summaries
     * @return array<string, mixed>
     */
    private function summaryByKey(array $summaries, string $key): array
    {
        foreach ($summaries as $item) {
            if (($item['key'] ?? '') === $key) {
                return $item;
            }
        }

        self::fail('Missing bulk summary for '.$key);
    }
}
