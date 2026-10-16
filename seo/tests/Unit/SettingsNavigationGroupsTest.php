<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Support\SeoSettingsMenu;
use PHPUnit\Framework\TestCase;

final class SettingsNavigationGroupsTest extends TestCase
{
    public function test_settings_sections_stay_on_their_routes_and_groups(): void
    {
        self::assertSame('general', SeoSettingsMenu::groupKey('general'));
        self::assertSame('ai', SeoSettingsMenu::groupKey('ai-center'));
        self::assertSame('ai', SeoSettingsMenu::groupKey('semantic-routing'));
        self::assertSame('ai', SeoSettingsMenu::groupKey('api'));
        self::assertSame('seo', SeoSettingsMenu::groupKey('keyword-sources'));
        self::assertSame('seo', SeoSettingsMenu::groupKey('editor'));
        self::assertSame('seo', SeoSettingsMenu::groupKey('scoring'));
        self::assertSame('automation', SeoSettingsMenu::groupKey('workflows'));
        self::assertSame('data', SeoSettingsMenu::groupKey('import-export'));
        self::assertSame('data', SeoSettingsMenu::groupKey('seeding-service'));
        self::assertSame('general', SeoSettingsMenu::groupKey('unknown-future-section'));

        $sidebar = (string) file_get_contents(dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/filament/pages/partials/seo-settings-sidebar.blade.php');
        self::assertStringContainsString('SeoSettingsMenu::groups()', $sidebar);
        self::assertStringNotContainsString('/admin/settings/', $sidebar);

        $workflows = (string) file_get_contents(dirname(__DIR__, 2).'/src/Filament/Pages/SeoSettingsWorkflows.php');
        self::assertStringContainsString("protected static ?string \$slug = 'settings/workflows'", $workflows);
        self::assertStringContainsString('settings_workflows.page_title', $workflows);
    }
}
