<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Omnichannel\Addons\SiteSync\Filament\Pages\SiteSyncOperationsCenter;
use Omnichannel\Addons\AiPrompt\Filament\Resources\DomainResource\Pages\Concerns\PersistsDomainPromptContext;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\CancelSiteSyncCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\ReconcileSiteSyncCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\RequeueSiteSyncInboundEventCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\ResumeSiteSyncCommand;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Ownership\SiteSyncOwnershipResolver;
use Omnichannel\Addons\SiteSync\Services\Reconciliation\KeywordNormalizationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SiteSyncV2Wave2FreezeTest extends TestCase
{
    public function test_bridge_min_version_wave2(): void
    {
        self::assertSame('1.0.64', SiteSyncSchema::MIN_BRIDGE_VERSION);
    }

    public function test_ownership_manual_beats_provider(): void
    {
        $resolver = new SiteSyncOwnershipResolver;
        $effective = $resolver->resolveEffective([
            'provider' => ['source' => SiteSyncSchema::SOURCE_PROVIDER, 'value' => 'from-wp'],
            'manual' => ['source' => SiteSyncSchema::SOURCE_MANUAL, 'value' => 'from-manual', 'locked' => true],
            'workspace' => ['source' => SiteSyncSchema::SOURCE_WORKSPACE, 'value' => 'from-ws'],
        ]);

        self::assertNotNull($effective);
        self::assertSame(SiteSyncSchema::SOURCE_MANUAL, $effective['source']);
        self::assertSame('from-manual', $effective['value']);
    }

    public function test_stale_detection_helper(): void
    {
        $resolver = new SiteSyncOwnershipResolver;
        self::assertTrue($resolver->isStale('2024-01-01T00:00:00Z', '2025-01-01T00:00:00Z'));
        self::assertFalse($resolver->isStale('2025-06-01T00:00:00Z', '2025-01-01T00:00:00Z'));
    }

    public function test_keyword_normalization_dedupe(): void
    {
        $svc = new KeywordNormalizationService;
        $out = $svc->dedupeCaseInsensitive(['  Foo  ', 'foo', 'Bar|Baz', "A\xC2\xA0B"]);
        $phrases = array_column($out, 'phrase');
        self::assertContains('Foo', $phrases);
        self::assertContains('Bar,Baz', $phrases);
        self::assertContains('A B', $phrases);
        self::assertCount(3, $out);
    }

    public function test_ops_center_uses_command_bus_not_models_mutate(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(SiteSyncOperationsCenter::class))->getFileName());
        self::assertStringContainsString('ContentProjectCommandBus', $src);
        self::assertStringContainsString('ResumeSiteSyncCommand', $src);
        self::assertStringContainsString('CancelSiteSyncCommand', $src);
        self::assertStringContainsString('RequeueSiteSyncInboundEventCommand', $src);
        self::assertStringContainsString('ReconcileSiteSyncCommand', $src);
    }

    public function test_domain_save_does_not_resolve_orchestrator(): void
    {
        $path = (new ReflectionClass(PersistsDomainPromptContext::class))->getFileName();
        $src = (string) file_get_contents((string) $path);
        self::assertStringNotContainsString('RunSiteSyncOrchestrator', $src);
        self::assertStringNotContainsString('DomainLinkListKeywordSyncService', $src);
        self::assertStringNotContainsString('seo:site-sync', $src);
    }

    public function test_control_commands_exist(): void
    {
        self::assertTrue(class_exists(ResumeSiteSyncCommand::class));
        self::assertTrue(class_exists(CancelSiteSyncCommand::class));
        self::assertTrue(class_exists(ReconcileSiteSyncCommand::class));
        self::assertTrue(class_exists(RequeueSiteSyncInboundEventCommand::class));
    }
}
