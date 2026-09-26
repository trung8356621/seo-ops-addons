<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Agent\Filament\Pages\AgentWorkspacePage;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentGateway;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceApplicationService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentGateway;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AgentWorkspaceExecutionTest extends TestCase
{
    public function test_agent_gateway_delegates_to_content_project_agent_gateway(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentGateway::class))->getFileName(),
        );

        self::assertStringContainsString('ContentProjectAgentGateway', $source);
        self::assertStringContainsString('$this->gateway->execute', $source);
        self::assertStringContainsString('ContentProjectAgentGateway::READ_CAPABILITIES', $source);
    }

    public function test_application_service_uses_orchestrator_not_command_bus(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspaceApplicationService::class))->getFileName(),
        );

        self::assertStringContainsString('AgentExecutionOrchestrator', $source);
        self::assertStringContainsString('$this->orchestrator->preview', $source);
        self::assertStringContainsString('$this->orchestrator->execute', $source);
        self::assertStringContainsString('$this->orchestrator->confirm', $source);
        self::assertStringNotContainsString('ContentProjectCommandBus', $source);
        self::assertStringNotContainsString('SeoProject::query()->create', $source);
        self::assertStringNotContainsString('SeoProject::create', $source);
        self::assertStringNotContainsString('$this->gateway->execute', $source);
    }

    public function test_skill_files_do_not_contain_eloquent_model_writes(): void
    {
        $skillsDir = ProjectRoot::addonsPath().'/agent/src/Services/AgentWorkspace/Skills';
        $files = glob($skillsDir.'/*.php') ?: [];

        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            self::assertStringNotContainsString('SeoProject::query()->create', $source, basename($file));
            self::assertStringNotContainsString('SeoProject::create', $source, basename($file));
            self::assertStringNotContainsString('->save(', $source, basename($file));
        }
    }

    public function test_agent_gateway_architecture_freeze_ui_page_vendor_isolation(): void
    {
        $pageSource = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspacePage::class))->getFileName(),
        );

        foreach (['WordPress', 'Gemini', 'Claude'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $pageSource, "AgentWorkspacePage must not reference {$forbidden}");
        }
    }
}
