<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Omnichannel\Addons\SiteSync\Filament\Pages\SiteSyncOperationsCenter;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Skills\OperationsSkills;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\ActivateSiteSyncV2Command;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\EnterSiteSyncShadowModeCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\RunSiteSyncCommand;
use Omnichannel\Addons\SiteSync\Services\Comparison\SiteSyncDifferenceClassifier;
use Omnichannel\Addons\SiteSync\Services\Cutover\SiteSyncCutoverModes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentCommandFactory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SiteSyncV2Wave4CutoverFreezeTest extends TestCase
{
    public function test_transitions_block_legacy_to_active(): void
    {
        self::assertFalse(SiteSyncCutoverModes::canTransition(
            SiteSyncCutoverModes::LEGACY_ACTIVE,
            SiteSyncCutoverModes::V2_ACTIVE,
        ));
        self::assertTrue(SiteSyncCutoverModes::canTransition(
            SiteSyncCutoverModes::LEGACY_ACTIVE,
            SiteSyncCutoverModes::V2_SHADOW,
        ));
        self::assertTrue(SiteSyncCutoverModes::canTransition(
            SiteSyncCutoverModes::V2_SHADOW,
            SiteSyncCutoverModes::V2_ACTIVE,
        ));
        self::assertTrue(SiteSyncCutoverModes::canTransition(
            SiteSyncCutoverModes::LEGACY_ACTIVE,
            SiteSyncCutoverModes::V2_ACTIVE,
            true,
        ));
    }

    public function test_difference_classifier_expected_codes(): void
    {
        $c = new SiteSyncDifferenceClassifier;
        $r = $c->classify('keyword', 'keyword_case_dedupe');
        self::assertSame(SiteSyncDifferenceClassifier::EXPECTED, $r['classification']);
        $b = $c->classify('article', 'manual_overwritten');
        self::assertSame(SiteSyncDifferenceClassifier::BLOCKING, $b['classification']);
        $p = $c->classify('score', 'provider_score_incomparable');
        self::assertSame(SiteSyncDifferenceClassifier::PROVIDER_FORMULA, $p['classification']);
    }

    public function test_ops_cutover_uses_command_bus(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(SiteSyncOperationsCenter::class))->getFileName());
        self::assertStringContainsString('EnterSiteSyncShadowModeCommand', $src);
        self::assertStringContainsString('ActivateSiteSyncV2Command', $src);
        self::assertStringContainsString('ContentProjectCommandBus', $src);
        self::assertStringContainsString('GenerateSiteSyncComparisonReportCommand', $src);
    }

    public function test_agent_site_sync_is_not_activate_cutover(): void
    {
        self::assertSame('site.sync', (new RunSiteSyncCommand(1))->name());
        self::assertSame('site.activate_v2', (new ActivateSiteSyncV2Command(1, null, 'tok'))->name());
        self::assertNotSame((new RunSiteSyncCommand(1))->name(), (new ActivateSiteSyncV2Command(1))->name());

        $factorySrc = (string) file_get_contents((new ReflectionClass(ContentProjectAgentCommandFactory::class))->getFileName());
        self::assertStringNotContainsString('ActivateSiteSyncV2Command', $factorySrc);
        self::assertStringNotContainsString('EnterSiteSyncShadowModeCommand', $factorySrc);

        $skillsSrc = (string) file_get_contents((new ReflectionClass(OperationsSkills::class))->getFileName());
        self::assertStringNotContainsString('activate_v2', $skillsSrc);
        self::assertStringNotContainsString('enter_shadow', $skillsSrc);
    }

    public function test_shadow_command_exists(): void
    {
        self::assertTrue(class_exists(EnterSiteSyncShadowModeCommand::class));
    }
}
