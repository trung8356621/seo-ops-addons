<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Enums\SeoProjectTaskStatus;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionCatalog;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionsPresenter;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectTaskStatusNormalizer;
use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;

final class ContentProjectManualStatusSwitchContractTest extends TestCase
{
    public function test_manual_override_options_use_canonical_enum_values(): void
    {
        $options = ContentProjectTaskStatusNormalizer::manualOverrideOptions();

        self::assertArrayHasKey(SeoProjectTaskStatus::Pending->value, $options);
        self::assertArrayHasKey(SeoProjectTaskStatus::Completed->value, $options);
        self::assertArrayNotHasKey(SeoProjectTaskStatus::Archived->value, $options);
        self::assertArrayNotHasKey(SeoProjectTaskStatus::Cancelled->value, $options);
        self::assertSame(
            ContentProjectTaskStatusNormalizer::manualOverrideValues(),
            array_keys($options),
        );
    }

    public function test_catalog_registers_change_status_single_action(): void
    {
        $def = ContentProjectItemActionCatalog::definition('change_status');
        self::assertNotNull($def);
        self::assertTrue($def['single']);
        self::assertFalse($def['bulk']);
        self::assertSame('change_status', $def['presenter_flag']);
        self::assertSame(ContentProjectItemActionCatalog::GROUP_LIFECYCLE, $def['group']);
    }

    public function test_presenter_exposes_change_status_flag(): void
    {
        $flags = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'draft',
            'queue_status' => 'none',
            'generation_badge' => ['key' => 'not_started'],
            'generation_status' => 'pending',
            'article_edit_url' => '/x',
        ]);

        self::assertArrayHasKey('change_status', $flags);
        self::assertTrue($flags['change_status']);
        self::assertTrue($flags['has_lifecycle']);
    }

    public function test_presenter_hides_change_status_when_archived_or_running(): void
    {
        $archived = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'archived',
            'queue_status' => 'none',
            'generation_badge' => ['key' => 'success'],
            'generation_status' => 'completed',
            'article_edit_url' => '/x',
        ]);
        self::assertFalse($archived['change_status']);

        $running = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'generating',
            'queue_status' => 'none',
            'generation_badge' => ['key' => 'running'],
            'generation_status' => 'writing',
            'is_genuinely_running' => true,
            'article_edit_url' => '/x',
        ]);
        self::assertFalse($running['change_status']);
    }

    public function test_ui_wires_modal_and_livewire_handler(): void
    {
        $menu = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-item-actions-menu.blade.php'),
        );
        self::assertStringContainsString('cp-ops-change-status', $menu);
        self::assertStringContainsString('item_action_change_status', $menu);

        $ops = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/seo-project-resource/pages/view-seo-project-operations.blade.php'),
        );
        self::assertStringContainsString('openChangeStatus', $ops);
        self::assertStringContainsString('changeItemStatus', $ops);
        self::assertStringContainsString('manualOverrideOptions', $ops);

        $page = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Filament/Resources/SeoProjectResource/Pages/ViewSeoProject.php',
        );
        self::assertStringContainsString('function changeItemStatus', $page);
        self::assertStringContainsString('UpdateContentProjectItemCommand', $page);
        self::assertStringContainsString('canManageContentProjectWorkflow', $page);
    }

    public function test_update_item_handler_allows_status_without_archive_cancel(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/Application/Handlers/UpdateContentProjectItemHandler.php',
        );
        self::assertStringContainsString("array_flip(['keyword', 'title', 'target_date', 'status', 'post_type'])", $src);
        self::assertStringContainsString('SeoProjectTaskStatus::Archived', $src);
        self::assertStringContainsString('SeoProjectTaskStatus::Cancelled', $src);
    }
}
