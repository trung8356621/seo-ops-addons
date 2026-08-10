<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Filament\Pages\AgentWorkspacePage;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentCapabilityDiagnosticsService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AgentWorkspaceDiagnosticsTest extends TestCase
{
    public function test_diagnostics_list_does_not_expose_raw_credentials(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentCapabilityDiagnosticsService::class))->getFileName(),
        );

        self::assertStringContainsString('capability_schema', $source);
        self::assertStringNotContainsString("'api_key'", $source);
        self::assertStringNotContainsString('credentials', $source);
        self::assertStringContainsString('no credential exposure', $source);
    }

    public function test_load_diagnostics_on_page_checks_manager_access(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspacePage::class))->getFileName(),
        );

        self::assertStringContainsString('function loadDiagnostics', $source);
        self::assertStringContainsString('canAccessManagerFeatures', $source);
    }
}
