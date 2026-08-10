<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptPreviewSectionViewModel;
use PHPUnit\Framework\TestCase;

final class PromptUsageBadgeViewModelTest extends TestCase
{
    public function test_orders_workflow_before_settings_alphabetically(): void
    {
        $vm = new PromptPreviewSectionViewModel;
        $ordered = $vm->orderedUsages([
            ['type' => 'settings', 'label' => 'Settings: Zeta', 'detail' => 'z'],
            ['type' => 'workflow', 'label' => 'Workflow: Beta', 'detail' => 'b'],
            ['type' => 'workflow', 'label' => 'Workflow: Alpha', 'detail' => 'a'],
            ['type' => 'settings', 'label' => 'Settings: FAQ', 'detail' => 'f'],
        ]);

        self::assertSame([
            'Workflow: Alpha',
            'Workflow: Beta',
            'Settings: FAQ',
            'Settings: Zeta',
        ], array_column($ordered, 'label'));
    }

    public function test_badge_single_and_plus(): void
    {
        $vm = new PromptPreviewSectionViewModel;

        $one = $vm->badge([
            ['type' => 'workflow', 'label' => 'Workflow: A', 'detail' => '1', 'name' => 'A'],
        ]);
        self::assertSame('Workflow', $one['badge']);

        $many = $vm->badge([
            ['type' => 'settings', 'label' => 'Settings: A', 'detail' => '1', 'name' => 'A'],
            ['type' => 'settings', 'label' => 'Settings: B', 'detail' => '2', 'name' => 'B'],
            ['type' => 'settings', 'label' => 'Settings: C', 'detail' => '3', 'name' => 'C'],
        ]);
        self::assertSame('Settings +2', $many['badge']);
        self::assertStringContainsString('Settings: A', (string) $many['tooltip']);
    }

    public function test_mixed_badge(): void
    {
        $vm = new PromptPreviewSectionViewModel;
        $badge = $vm->badge([
            ['type' => 'workflow', 'label' => 'Workflow: A', 'detail' => '1', 'name' => 'A'],
            ['type' => 'settings', 'label' => 'Settings: B', 'detail' => '2', 'name' => 'B'],
        ]);
        self::assertSame('Workflow + Settings', $badge['badge']);
        self::assertSame('mixed', $badge['kind']);
    }
}
