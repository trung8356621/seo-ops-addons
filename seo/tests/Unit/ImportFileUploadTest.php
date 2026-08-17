<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Exceptions\ConfigurationPackageException;
use Omnichannel\Addons\AiPrompt\Services\ConfigurationPackages\ConfigurationPackageParser;
use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class ImportFileUploadTest extends TestCase
{
    public function test_normal_ui_uses_file_input_and_hides_textarea(): void
    {
        $view = (string) file_get_contents(ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/pages/seo-settings-configuration-transfer.blade.php');
        $this->assertStringContainsString('wire:model="importFile"', $view);
        $this->assertStringContainsString('accept=".json,application/json"', $view);
        $this->assertStringContainsString('drop_json', $view);
        $this->assertStringContainsString('class="sr-only"', $view);
        $this->assertStringContainsString('choose_json', $view);
        $this->assertStringContainsString('advanced_paste', $view);
        $this->assertTrue(strpos($view, 'wire:model="importFile"') < strpos($view, '<details'));
        $this->assertTrue(strpos($view, '<details') < strpos($view, 'wire:model="importJson"'));
    }

    public function test_invalid_json_is_rejected_before_apply(): void
    {
        $this->expectException(ConfigurationPackageException::class);
        (new ConfigurationPackageParser())->parse('{not-json');
    }

    public function test_valid_settings_package_parses_for_preview(): void
    {
        $parsed = (new ConfigurationPackageParser())->parse(json_encode([
            'package_type' => 'seo_settings',
            'schema_version' => '1.0',
            'settings' => ['general' => ['a' => 1]],
        ], JSON_THROW_ON_ERROR));
        $this->assertSame('seo_settings', $parsed['type']->value);
        $this->assertSame('1.0', $parsed['schema_version']);
    }
}
