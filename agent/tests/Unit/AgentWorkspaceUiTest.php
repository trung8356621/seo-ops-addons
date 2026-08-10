<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;


use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Agent\Filament\Pages\AgentWorkspacePage;
use App\Addons\SeoContentAi\Providers\SeoPanelProvider;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceApplicationService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceUiContext;
use App\Filament\Pages\AgentWorkspaceRedirect;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class AgentWorkspaceUiTest extends TestCase
{
    public function test_agent_workspace_page_slug_and_navigation_group(): void
    {
        $reflection = new ReflectionClass(AgentWorkspacePage::class);

        $slug = $reflection->getStaticPropertyValue('slug');
        $navigationGroup = $reflection->getStaticPropertyValue('navigationGroup');
        $view = $reflection->getStaticPropertyValue('view');

        self::assertSame('agent', $slug);
        self::assertNull($navigationGroup);
        self::assertSame('seo-content-ai::filament.pages.agent-workspace', $view);
    }

    public function test_agent_workspace_blade_view_exists(): void
    {
        $viewPath = LegacyAddonPath::resolve('resources/views/filament/pages/agent-workspace.blade.php');
        self::assertFileExists($viewPath);

        $source = (string) file_get_contents($viewPath);
        self::assertStringContainsString('seo-agent-workspace-chat', $source);
        self::assertStringContainsString('seo-agent-chat.header', $source);
        self::assertStringContainsString('seo-agent-chat.composer', $source);
        self::assertStringContainsString('seo-agent-chat.empty-state', $source);
        self::assertStringContainsString('action="selectTemplate"', $source);
        self::assertStringContainsString('selectPaletteElement($el)', $source);
        self::assertStringNotContainsString('@js(', $source);
        self::assertStringNotContainsString('Js::from(', $source);
        self::assertStringNotContainsString("wire:target=\"openTemplate('", $source);
        self::assertStringContainsString('x-data="seoAgentWorkspace"', $source);
        self::assertStringContainsString('Alpine.data', $source);
        self::assertStringContainsString('element.value', $source);
        self::assertStringContainsString('selectPaletteElement($el)', $source);
        self::assertStringContainsString('<x-filament-panels::page>', $source);
        self::assertStringContainsString("@vite([", $source);
        self::assertTrue(
            strpos($source, '<x-filament-panels::page>') < strpos($source, '@vite(['),
            'Vite assets must load inside Filament page root (single Livewire root)',
        );
        self::assertStringNotContainsString('agent-workspace.general-panel', $source);
        self::assertStringNotContainsString('MCP Markdown', $source);
    }

    public function test_agent_workspace_does_not_embed_mcp_markdown(): void
    {
        $pageSource = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspacePage::class))->getFileName(),
        );
        $viewPath = LegacyAddonPath::resolve('resources/views/filament/pages/agent-workspace.blade.php');
        $viewSource = (string) file_get_contents($viewPath);

        self::assertStringNotContainsString('McpCapabilityMarkdownPresenter', $pageSource);
        self::assertStringNotContainsString('mcpCapabilityDoc', $pageSource);
        self::assertStringNotContainsString('MCP Markdown', $viewSource);
        self::assertFileDoesNotExist(
            LegacyAddonPath::resolve('resources/views/filament/pages/partials/agent-workspace/general-panel.blade.php'),
        );
    }

    public function test_shared_chat_components_exist(): void
    {
        $base = LegacyAddonPath::resolve('resources/views/components/seo-agent-chat');
        foreach (['star-icon', 'empty-state', 'disclaimer', 'header', 'message', 'composer'] as $name) {
            self::assertFileExists($base.'/'.$name.'.blade.php', $name);
        }
    }

    public function test_admin_agent_workspace_redirect_slug(): void
    {
        $reflection = new ReflectionClass(AgentWorkspaceRedirect::class);
        $slug = $reflection->getStaticPropertyValue('slug');

        self::assertSame('agent', $slug);
    }

    public function test_global_ai_chat_ai_star_is_agent_workspace_launcher(): void
    {
        $bladePath = LegacyAddonPath::resolve('resources/views/components/global-ai-chat.blade.php');
        self::assertFileExists($bladePath);

        $source = (string) file_get_contents($bladePath);

        self::assertStringContainsString('openAgentWorkspace', $source);
        self::assertStringContainsString('AgentWorkspaceDeepLink::forCurrentRequest', $source);
        self::assertStringContainsString('agent_workspace.open_workspace', $source);
        self::assertStringContainsString('window.location.assign', $source);
        self::assertStringNotContainsString("x-on:click=\"switchTab('ai')\"", $source);
        self::assertStringContainsString("x-on:click=\"openAgentWorkspace()\"", $source);
        self::assertStringContainsString('seo-agent-chat.star-icon', $source);
    }

    public function test_popup_does_not_call_agent_application_service(): void
    {
        $bladePath = LegacyAddonPath::resolve('resources/views/components/global-ai-chat.blade.php');
        $source = (string) file_get_contents($bladePath);

        self::assertStringNotContainsString('AgentWorkspaceApplicationService', $source);
        self::assertStringNotContainsString('ContentProjectCommandBus', $source);
        self::assertStringNotContainsString('AgentGateway', $source);
    }

    public function test_page_exposes_unified_select_skill_and_template(): void
    {
        $page = new ReflectionClass(AgentWorkspacePage::class);

        self::assertTrue($page->hasMethod('selectSkill'));
        self::assertTrue($page->hasMethod('selectTemplate'));
        self::assertTrue($page->hasMethod('clearSkillSelection'));

        $selectSkill = new ReflectionMethod(AgentWorkspacePage::class, 'selectSkill');
        self::assertTrue($selectSkill->isPublic());

        $selectTemplate = new ReflectionMethod(AgentWorkspacePage::class, 'selectTemplate');
        self::assertTrue($selectTemplate->isPublic());

        $source = (string) file_get_contents((string) $page->getFileName());
        $selectSkillBody = $this->extractMethodBody($source, 'selectSkill');
        $selectTemplateBody = $this->extractMethodBody($source, 'selectTemplate');

        self::assertStringNotContainsString('AgentGateway', $selectSkillBody);
        self::assertStringNotContainsString('gateway->execute', $selectSkillBody);
        self::assertStringNotContainsString('CommandBus', $selectSkillBody);
        self::assertStringNotContainsString('->execute(', $selectSkillBody);
        self::assertStringContainsString('openSkill', $selectSkillBody);

        self::assertStringContainsString('selectSkill', $selectTemplateBody);
        self::assertStringNotContainsString('gateway->execute', $selectTemplateBody);
        self::assertStringNotContainsString('CommandBus', $selectTemplateBody);
    }

    public function test_select_skill_resolves_from_registry_not_browser_definition(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspacePage::class))->getFileName(),
        );
        $body = $this->extractMethodBody($source, 'selectSkill');

        self::assertStringContainsString('AgentSkillRegistry', $body);
        self::assertStringContainsString('resolveSlashCommand', $body);
        self::assertStringNotContainsString('fake skill definition', $body);
    }

    public function test_recommended_skills_are_not_html_disabled(): void
    {
        $path = LegacyAddonPath::resolve('resources/views/filament/pages/partials/agent-context-panel.blade.php');
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('action="selectCommand"', $source);
        self::assertStringContainsString('agent-workspace.action-button', $source);
        self::assertStringContainsString(':value="$row[\'key\']"', $source);
        self::assertStringNotContainsString('@js(', $source);
        self::assertStringNotContainsString('openSkill(', $source);
        self::assertStringContainsString('openSkillBrowser', $source);
        self::assertStringContainsString('seo-agent-workspace__quick-cmd', $source);
    }

    public function test_skill_form_hides_execute_when_unavailable(): void
    {
        $path = LegacyAddonPath::resolve('resources/views/filament/pages/partials/agent-skill-form.blade.php');
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('clearSkillSelection', $source);
        self::assertStringContainsString('$usable', $source);
        self::assertStringContainsString('@if ($usable)', $source);
    }

    public function test_global_chat_suppressed_on_agent_workspace(): void
    {
        $providerSource = (string) file_get_contents(
            (new ReflectionClass(SeoPanelProvider::class))->getFileName(),
        );
        self::assertStringContainsString('AgentWorkspaceUiContext::hidesGlobalChat', $providerSource);

        $uiContext = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspaceUiContext::class))->getFileName(),
        );
        self::assertStringContainsString('filament.seo.pages.agent', $uiContext);
        self::assertStringContainsString('filament.admin.pages.agent', $uiContext);
        self::assertStringContainsString('hidesGlobalChat', $uiContext);
    }

    public function test_application_open_skill_still_does_not_execute(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspaceApplicationService::class))->getFileName(),
        );
        $openSkillBody = $this->extractMethodBody($source, 'openSkill');

        self::assertStringNotContainsString('gateway->execute', $openSkillBody);
        self::assertStringNotContainsString('$this->gateway->execute', $openSkillBody);
    }

    private function extractMethodBody(string $source, string $methodName): string
    {
        if (! preg_match('/function\s+'.$methodName.'\s*\([^)]*\)[^{]*\{/', $source, $match, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $start = $match[0][1] + strlen($match[0][0]);
        $depth = 1;
        $length = strlen($source);

        for ($i = $start; $i < $length; $i++) {
            $char = $source[$i];
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start);
                }
            }
        }

        return '';
    }
}
