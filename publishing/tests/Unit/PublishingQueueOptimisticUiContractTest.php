<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithContentProjectPublishingActions;
use Omnichannel\Addons\Publishing\Filament\Pages\PublishingQueueHub;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

final class PublishingQueueOptimisticUiContractTest extends TestCase
{
    public function test_select_page_php_still_uses_visible_page_ids_only(): void
    {
        $hub = $this->source(PublishingQueueHub::class);
        $pos = strpos($hub, 'function selectPage');
        self::assertNotFalse($pos);
        $next = strpos($hub, "\n    public function ", $pos + 1);
        $chunk = $next !== false ? substr($hub, $pos, $next - $pos) : substr($hub, $pos);

        self::assertStringContainsString("queuePayload['rows']", $chunk);
        self::assertStringContainsString('task_id', $chunk);
        self::assertStringContainsString('selectAllMatching = false', $chunk);
        self::assertStringNotContainsString('forHub', $chunk);
    }

    public function test_toggle_page_selection_only_merges_current_page_ids(): void
    {
        $hub = $this->source(PublishingQueueHub::class);
        $pos = strpos($hub, 'function togglePageSelection');
        self::assertNotFalse($pos);
        $next = strpos($hub, "\n    public function ", $pos + 1);
        $chunk = $next !== false ? substr($hub, $pos, $next - $pos) : substr($hub, $pos);

        self::assertStringContainsString('array_diff($pageIds, $selected)', $chunk);
        self::assertStringContainsString('array_merge($selected, $pageIds)', $chunk);
        self::assertSame(
            [11, 12, 13],
            $this->selectPageIds([['task_id' => 11], ['task_id' => 12], ['task_id' => 13]]),
        );
    }

    public function test_optimistic_selection_does_not_wait_for_livewire_round_trip(): void
    {
        $list = $this->view('components/content-project-items-list.blade.php');
        $toolbar = $this->view('components/content-project-filter-toolbar.blade.php');
        $bulk = $this->view('components/content-project-bulk-selection-toolbar.blade.php');
        $ui = $this->view('components/publishing-queue-optimistic-ui.blade.php');
        $hub = $this->view('filament/pages/publishing-queue-hub.blade.php');

        self::assertStringContainsString('$store.pqOpsUi.selectPage()', $toolbar);
        self::assertStringContainsString('$store.pqOpsUi.togglePage()', $list);
        self::assertStringContainsString('$store.pqOpsUi.toggleRow', $list);
        self::assertStringContainsString("wire.set('selectedTaskIds'", $ui);
        self::assertStringContainsString(', false)', $ui);
        self::assertStringContainsString('runBulk(', $ui);
        self::assertStringNotContainsString('wire:model.live="selectedTaskIds"', $this->pqCheckboxChunk($list));
        self::assertStringContainsString('wire:model.live="selectedTaskIds"', $list);
        self::assertStringContainsString('publishing-queue-optimistic-ui', $hub);
        self::assertStringContainsString('$store.pqOpsUi.selectedCount()', $bulk);
    }

    public function test_last_activity_processing_is_optimistic_and_reconciles(): void
    {
        $list = $this->view('components/content-project-items-list.blade.php');
        $menu = $this->view('components/publishing-queue-item-actions-menu.blade.php');
        $bulk = $this->view('components/content-project-bulk-selection-toolbar.blade.php');
        $hub = $this->view('filament/pages/publishing-queue-hub.blade.php');
        $readModel = $this->source(\Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueReadModel::class);

        self::assertStringContainsString("kind: 'publishing'", $menu);
        self::assertStringContainsString("\$dispatch('cp-ops-row-processing'", $menu);
        self::assertStringContainsString('runBulk(', $bulk);
        self::assertStringContainsString('is_activity_processing', $list);
        self::assertStringContainsString('is_activity_processing', $readModel);
        $activityPos = strpos($readModel, "row['is_activity_processing']");
        self::assertNotFalse($activityPos);
        $activityChunk = substr($readModel, $activityPos, 180);
        self::assertStringContainsString('PUBLISHING', $activityChunk);
        self::assertStringNotContainsString('AWAITING_DELIVERY', $activityChunk);
        self::assertStringContainsString('pendingTaskIds', $hub);
        self::assertStringContainsString('processingRows = {}', $hub);
    }

    public function test_optimistic_click_does_not_dispatch_command_twice(): void
    {
        $menu = $this->view('components/publishing-queue-item-actions-menu.blade.php');
        $bulk = $this->view('components/content-project-bulk-selection-toolbar.blade.php');
        $ui = $this->view('components/publishing-queue-optimistic-ui.blade.php');
        $trait = $this->source(InteractsWithContentProjectPublishingActions::class);

        self::assertStringContainsString('wire:click="publishOneNow', $menu);
        self::assertStringContainsString("runBulk('bulkPublishNow'", $bulk);
        self::assertSame(1, substr_count($bulk, "runBulk('bulkPublishNow'"));
        self::assertStringNotContainsString('wire:click="bulkPublishNow"', $bulk);
        self::assertStringNotContainsString('$wire.bulkPublishNow', $bulk);
        self::assertStringNotContainsString('$wire.publishOneNow', $menu);
        self::assertSame(1, substr_count($menu, 'wire:click="publishOneNow({{ $tid }})"'));
        self::assertStringContainsString('syncSelectionToLivewire().then(', $ui);
        self::assertStringContainsString('if (($this->bulkRunning ?? false) === true)', $trait);
        self::assertStringContainsString('ContentProjectCommandBus', $trait);
        self::assertSame(1, substr_count($trait, '->dispatch('));
    }

    /**
     * @param  list<array{task_id: int}>  $rows
     * @return list<int>
     */
    private function selectPageIds(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row['task_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function pqCheckboxChunk(string $list): string
    {
        $start = strpos($list, '$isPublishingQueue');
        self::assertNotFalse($start);
        $toggle = strpos($list, 'toggleRow', $start);
        self::assertNotFalse($toggle);

        return substr($list, $start, $toggle - $start + 80);
    }

    private function view(string $relative): string
    {
        return (string) file_get_contents(LegacyAddonPath::resolve('resources/views/'.$relative));
    }

    /**
     * @param  class-string  $class
     */
    private function source(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        self::assertNotFalse($file);

        return (string) file_get_contents($file);
    }
}
