<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Filament\Pages\AgentWorkspacePage;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentIntentRouter;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceApplicationService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentIntentResolution;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services\DefaultAgentPlanningOrchestrator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services\ProviderAgentModelGateway;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AgentNaturalLanguagePlanningTest extends TestCase
{
    public function test_slash_path_does_not_reference_planning_orchestrator_in_router(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(AgentIntentRouter::class))->getFileName());
        self::assertStringNotContainsString('AgentPlanningOrchestrator', $source);
        self::assertStringNotContainsString('AgentModelGateway', $source);
    }

    public function test_page_routes_assistant_source_to_planning(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(AgentWorkspacePage::class))->getFileName());
        self::assertStringContainsString('SOURCE_ASSISTANT', $source);
        self::assertStringContainsString('runNaturalLanguagePlanning', $source);
        self::assertStringContainsString('planNaturalLanguage', $source);
        self::assertStringContainsString('saveProposedPlan', $source);
        self::assertStringContainsString('submitClarification', $source);
    }

    public function test_application_service_exposes_planning_without_gateway_execute(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(AgentWorkspaceApplicationService::class))->getFileName());
        self::assertStringContainsString('planNaturalLanguage', $source);
        self::assertStringContainsString('AgentPlanningOrchestrator', $source);
        self::assertStringNotContainsString('ContentProjectCommandBus', $source);
    }

    public function test_gateway_uses_provider_interface_not_vendor_sdk(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(ProviderAgentModelGateway::class))->getFileName());
        self::assertStringContainsString('AiTextProviderInterface', $source);
        self::assertStringContainsString('AiProviderResolver', $source);
        self::assertStringNotContainsString('GeminiAiTextProvider', $source);
        self::assertStringNotContainsString('ClaudeAiTextProvider', $source);
    }

    public function test_orchestrator_never_marks_executed_true(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(DefaultAgentPlanningOrchestrator::class))->getFileName());
        self::assertStringContainsString("'executed' => false", $source);
        self::assertStringNotContainsString("'executed' => true", $source);
        self::assertStringContainsString('createPlan', $source);
    }

    public function test_intent_resolution_sources_cover_deterministic_vs_ai(): void
    {
        self::assertSame('slash', AgentIntentResolution::SOURCE_SLASH);
        self::assertSame('assistant', AgentIntentResolution::SOURCE_ASSISTANT);
        self::assertSame('ai', AgentIntentResolution::SOURCE_AI);
    }
}
