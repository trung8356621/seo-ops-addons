<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;


use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\AiPrompt\Filament\Resources\DomainResource\Pages\Concerns\PersistsDomainPromptContext;
use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages\GeneralDomain;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBusRegistrar;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\ForceFullSiteSyncCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\RunSiteSyncCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCommandHandler;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncBatchData;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Inbound\SiteSyncStagingWriter;
use Omnichannel\Addons\SiteSync\Services\Orchestration\RunSiteSyncOrchestrator;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncStepClaimResult;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Freeze: force_full site sync must work on bootstrapped / completed sites.
 */
final class SiteSyncV2ForceFullFreezeTest extends TestCase
{
    public function test_force_full_mode_constant_exists(): void
    {
        self::assertSame('force_full', SiteSyncSchema::MODE_FORCE_FULL);
    }

    public function test_batch_decoder_accepts_force_full(): void
    {
        $batch = SiteSyncBatchData::fromArray([
            'schema' => SiteSyncSchema::VERSION,
            'mode' => SiteSyncSchema::MODE_FORCE_FULL,
            'articles' => [['wp_id' => 1]],
            'links' => [],
            'provider_keywords' => [],
            'scores' => [],
            'total_count' => 1174,
            'include_unchanged' => true,
        ]);

        self::assertSame(SiteSyncSchema::MODE_FORCE_FULL, $batch->mode);
        self::assertSame(1174, (int) ($batch->raw['total_count'] ?? 0));
        self::assertTrue((bool) ($batch->raw['include_unchanged'] ?? false));
    }

    public function test_force_full_command_payload(): void
    {
        $cmd = new ForceFullSiteSyncCommand(
            siteId: 42,
            supersedeActive: true,
            idempotencyKey: 'idem-1',
            operationId: 'op-1',
        );

        self::assertSame('site.sync.force_full', $cmd->name());
        self::assertSame(SiteSyncSchema::MODE_FORCE_FULL, $cmd->mode());
        self::assertSame(42, $cmd->siteId);
        self::assertTrue($cmd->supersedeActive);
    }

    public function test_run_site_sync_default_is_not_force_full(): void
    {
        $cmd = new RunSiteSyncCommand(siteId: 1);
        self::assertSame('delta', $cmd->mode);
        self::assertNotSame(SiteSyncSchema::MODE_FORCE_FULL, $cmd->mode);
    }

    public function test_handler_priority_force_full_before_bootstrap(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SiteSyncCommandHandler::class))->getFileName()
        );

        self::assertStringContainsString('ForceFullSiteSyncCommand', $src);
        self::assertStringContainsString('force_full → bootstrap', $src);
        self::assertStringContainsString('MODE_FORCE_FULL', $src);

        $forcePos = strpos($src, 'MODE_FORCE_FULL');
        $bootstrapPos = strpos($src, 'needsBootstrap($site)');
        self::assertNotFalse($forcePos);
        self::assertNotFalse($bootstrapPos);
        self::assertLessThan($bootstrapPos, $forcePos, 'force_full check must precede bootstrap');
    }

    public function test_orchestrator_accepts_force_full_options(): void
    {
        $method = new ReflectionMethod(RunSiteSyncOrchestrator::class, 'start');
        $doc = (string) $method->getDocComment();
        self::assertStringContainsString('force_full', $doc);

        $src = (string) file_get_contents(
            (new ReflectionClass(RunSiteSyncOrchestrator::class))->getFileName()
        );
        self::assertStringContainsString('MODE_FORCE_FULL', $src);
        self::assertStringContainsString('include_unchanged', $src);
        self::assertStringContainsString('superseded_by_force_full', $src);
        self::assertStringContainsString("'cursor' => null", $src);
    }

    public function test_step_runner_force_full_skips_modified_since(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncStepRunner::class))->getFileName()
        );
        self::assertStringContainsString("include_unchanged' => true", $src);
        self::assertStringContainsString('never modified-since', $src);
        self::assertStringContainsString('total_to_check', $src);
        self::assertStringContainsString('SiteSyncStepClaimResult::Claimed', $src);
        self::assertStringContainsString('Force full sync discovered zero WordPress records', $src);
    }

    public function test_force_full_batches_are_attempt_scoped(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SiteSyncStagingWriter::class))->getFileName()
        );

        self::assertStringContainsString('bool $attemptScoped = false', $src);
        self::assertStringContainsString("'|run:'.\$runId", $src);

        $runner = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncStepRunner::class))->getFileName()
        );
        self::assertStringContainsString('$this->staging->stage($site, $batch, (int) $run->id, $forceFull)', $runner);
    }

    public function test_step_claim_result_is_explicit(): void
    {
        self::assertSame('claimed', SiteSyncStepClaimResult::Claimed->value);
        self::assertSame('already_completed', SiteSyncStepClaimResult::AlreadyCompleted->value);
        self::assertSame('invalid_run_state', SiteSyncStepClaimResult::InvalidRunState->value);
        self::assertSame('tenant_mismatch', SiteSyncStepClaimResult::TenantMismatch->value);
    }

    public function test_command_bus_registers_force_full(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ContentProjectCommandBusRegistrar::class))->getFileName()
        );
        self::assertStringContainsString('ForceFullSiteSyncCommand::class', $src);
    }

    public function test_domain_ui_always_exposes_force_full_checkbox(): void
    {
        $blade = LegacyAddonPath::resolve('resources/views/filament/resources/domain-resource/pages/partials/domain-sync-actions.blade.php');
        self::assertFileExists($blade);
        $src = (string) file_get_contents($blade);
        self::assertStringContainsString('wire:model.live="siteSyncForceFull"', $src);
        self::assertStringContainsString('Đồng bộ lại toàn bộ website', $src);
        self::assertStringContainsString('runForceFullSiteSyncAction', $src);
        self::assertStringContainsString('Tải và kiểm tra lại toàn bộ bài viết', $src);
        // Must not gate checkbox on resumable/failed only.
        self::assertStringNotContainsString('@if ($siteSyncV2Resumable', $src);
    }

    public function test_domain_ui_dispatches_force_full_via_command_bus(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(GeneralDomain::class))->getFileName()
        );
        self::assertStringContainsString('ForceFullSiteSyncCommand', $src);
        self::assertStringContainsString('runForceFullSiteSyncAction', $src);
        self::assertStringContainsString('dispatchSiteSyncBus(new ForceFullSiteSyncCommand', $src);
        self::assertStringContainsString('siteSyncForceFull', $src);
    }

    public function test_domain_save_does_not_trigger_force_full(): void
    {
        $path = (new ReflectionClass(PersistsDomainPromptContext::class))->getFileName();
        self::assertNotFalse($path);
        $src = (string) file_get_contents($path);
        self::assertStringNotContainsString('ForceFullSiteSyncCommand', $src);
        self::assertStringNotContainsString('force_full', $src);
    }

    public function test_legacy_sync_service_not_called_from_force_full_path(): void
    {
        $handler = (string) file_get_contents(
            (new ReflectionClass(SiteSyncCommandHandler::class))->getFileName()
        );
        self::assertStringNotContainsString('SyncDomainContentService', $handler);
        self::assertStringNotContainsString('RunIncrementalDomainSyncJob', $handler);

        $orch = (string) file_get_contents(
            (new ReflectionClass(RunSiteSyncOrchestrator::class))->getFileName()
        );
        self::assertStringNotContainsString('SyncDomainContentService', $orch);
    }
}
