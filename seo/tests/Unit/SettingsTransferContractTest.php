<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsConfigurationTransfer;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\AiCenterSettingsSection;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\ArrayOptionSection;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\SeoSettingsBundleService;
use Omnichannel\Addons\Seo\Support\SeoSettingsMenu;
use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class SettingsTransferContractTest extends TestCase
{
    public function test_merge_leaves_missing_fields_and_replace_resets_section(): void
    {
        $store = [
            'a' => 'one',
            'b' => 'two',
            'c' => 'three',
        ];
        $section = new ArrayOptionSection(
            'general',
            ['a', 'b', 'c'],
            fn (): array => $store,
            function (array $data) use (&$store): void {
                $store = $data;
            },
        );

        $section->apply(1, ['a' => 'changed'], 'merge');
        $this->assertSame(['a' => 'changed', 'b' => 'two', 'c' => 'three'], $store);

        $section->apply(1, ['a' => 'only'], 'replace');
        $this->assertSame(['a' => 'only'], $store);
    }

    public function test_unknown_incoming_keys_are_ignored(): void
    {
        $store = ['a' => 'one'];
        $section = new ArrayOptionSection(
            'general',
            ['a'],
            fn (): array => $store,
            function (array $data) use (&$store): void {
                $store = $data;
            },
        );
        $diff = $section->diff(1, ['a' => 'two', 'injected' => 'nope']);
        $this->assertSame(['a' => 'two'], $diff['payload']);
        $section->apply(1, ['a' => 'two', 'injected' => 'nope'], 'merge');
        $this->assertArrayNotHasKey('injected', $store);
    }

    public function test_round_trip_normalized_payload_matches(): void
    {
        $store = ['a' => true, 'b' => ['x' => 1]];
        $section = new ArrayOptionSection(
            'general',
            ['a', 'b'],
            fn (): array => $store,
            function (array $data) use (&$store): void {
                $store = array_merge($store, $data);
            },
        );
        $exported = $section->export(1);
        $store = ['a' => false, 'b' => []];
        $section->apply(1, $exported, 'merge');
        $this->assertSame($exported, $section->export(1));
    }

    public function test_ai_center_export_uses_portable_connection_refs(): void
    {
        $src = (string) file_get_contents((new \ReflectionClass(AiCenterSettingsSection::class))->getFileName());
        $this->assertStringContainsString('connection_ref', $src);
        $this->assertStringContainsString('provider_key', $src);
        $this->assertStringContainsString("'exported' => false", $src);
        $this->assertStringNotContainsString("'connection_id'", $src);
        $this->assertStringNotContainsString('ImageRoutingStrategy', $src);
    }

    public function test_bundle_excludes_runtime_data_and_recommendations(): void
    {
        $src = (string) file_get_contents((new \ReflectionClass(SeoSettingsBundleService::class))->getFileName());
        $this->assertStringContainsString('recommendations', $src);
        $this->assertStringContainsString('Global Help topics', $src);
        $this->assertStringNotContainsString('ImageRoutingStrategy', $src);
    }

    public function test_import_export_is_not_a_sidebar_item(): void
    {
        $page = (string) file_get_contents((new \ReflectionClass(SeoSettingsConfigurationTransfer::class))->getFileName());
        $this->assertStringContainsString('shouldRegisterNavigation = false', $page);
        $menu = (string) file_get_contents((new \ReflectionClass(SeoSettingsMenu::class))->getFileName());
        $this->assertStringContainsString('SettingsSectionRegistry', $menu);
        $contributor = (string) file_get_contents(
            (new \ReflectionClass(\Omnichannel\Addons\Seo\Settings\SeoSettingsSectionContributor::class))->getFileName()
        );
        $this->assertStringContainsString('SeoSettingsConfigurationTransfer', $contributor);
        $this->assertStringContainsString("id: 'import-export'", $contributor);
        unset($page);
        $this->assertDirectoryExists(ProjectRoot::addonsPath().'/seo/src/Filament/Pages');
    }
}
