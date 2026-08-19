<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class BulkSelectReloadGuardTest extends TestCase
{
    public function test_seo_panel_registers_global_bulk_select_reload_guard(): void
    {
        $provider = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/Providers/SeoPanelProvider.php',
        );

        self::assertStringContainsString('filament.hooks.bulk-select-reload-guard', $provider);
    }

    public function test_guard_blocks_f5_and_ctrl_r_when_bulk_rows_are_selected(): void
    {
        $script = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/hooks/bulk-select-reload-guard.blade.php',
        );

        self::assertStringContainsString("event.key === 'F5'", $script);
        self::assertStringContainsString('hasBulkSelection()', $script);
        self::assertStringContainsString('event.preventDefault()', $script);
        self::assertStringContainsString('.fi-ta-record-checkbox:checked', $script);
        self::assertStringContainsString('selectedArticleIds', $script);
        self::assertStringContainsString('selectedTaskIds', $script);
        self::assertStringNotContainsString('selectedScoringRuleKeys', $script);
    }
}
