<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Omnichannel\Addons\SiteSync\Filament\Pages\SiteSyncOperationsCenter;
use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Forms\DomainTechnicalSeoForm;
use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages\GeneralDomain;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\BootstrapSiteSyncCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\PreviewBootstrapSiteSyncCommand;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Presentation\SiteSyncSourceLabelPresenter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SiteSyncV2Wave3PatchIntegrityTest extends TestCase
{
    public function test_placeholder_content_closure_appears_once(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(DomainTechnicalSeoForm::class))->getFileName());
        self::assertSame(1, substr_count($src, '->content(function'));
        self::assertStringContainsString('Forms\\Components\\Placeholder $component', $src);
        self::assertStringContainsString('EffectiveDomainLinkResolver', $src);
        self::assertStringNotContainsString('->content(function ($livewire)', $src);
    }

    public function test_ops_center_single_notification_builder(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(SiteSyncOperationsCenter::class))->getFileName());
        self::assertSame(1, substr_count($src, 'Notification::make()'));
        self::assertStringNotContainsString('->{$result->success', $src);
    }

    public function test_general_domain_uses_command_bus_and_clean_notifications(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(GeneralDomain::class))->getFileName());
        self::assertStringContainsString('ContentProjectCommandBus', $src);
        self::assertStringContainsString('BootstrapSiteSyncCommand', $src);
        self::assertStringContainsString('notifySiteSyncResult', $src);
        self::assertStringNotContainsString('->{$result[\'success\']', $src);
        self::assertStringNotContainsString('RunSiteSyncOrchestrator::class)->start', $src);
    }

    public function test_wave3_commands_exist(): void
    {
        self::assertTrue(class_exists(PreviewBootstrapSiteSyncCommand::class));
        self::assertTrue(class_exists(BootstrapSiteSyncCommand::class));
        self::assertSame('1.0.64', SiteSyncSchema::MIN_BRIDGE_VERSION);
        self::assertSame(SiteSyncSchema::SOURCE_LEGACY_UNKNOWN, 'legacy_unknown');
    }

    public function test_source_label_no_plugin(): void
    {
        $chips = (new SiteSyncSourceLabelPresenter)->chips([
            'provider' => 'none',
            'seo_score' => ['sources' => ['workspace']],
            'keyword' => ['provider' => 'none', 'workspace_fallback' => true],
            'http_404' => ['source' => 'Workspace fallback'],
        ]);
        $labels = array_column($chips, 'label');
        self::assertTrue(in_array('SEO provider: Không phát hiện', $labels, true));
        self::assertTrue(in_array('SEO score: SEO Workspace', $labels, true));
        self::assertTrue(in_array('Keyword: SEO Workspace fallback', $labels, true));
    }

    public function test_php_files_parse(): void
    {
        $files = [
            (new ReflectionClass(DomainTechnicalSeoForm::class))->getFileName(),
            (new ReflectionClass(SiteSyncOperationsCenter::class))->getFileName(),
            (new ReflectionClass(GeneralDomain::class))->getFileName(),
        ];
        foreach ($files as $file) {
            self::assertNotFalse($file);
            $out = [];
            $code = 0;
            exec('php -l '.escapeshellarg((string) $file).' 2>&1', $out, $code);
            self::assertSame(0, $code, implode("\n", $out));
        }
    }
}
