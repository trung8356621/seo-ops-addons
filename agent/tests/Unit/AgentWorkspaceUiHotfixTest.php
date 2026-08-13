<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Agent\Filament\Pages\AgentWorkspacePage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Interaction-contract tests for Agent Workspace v1 UI hotfix (P0).
 * Source-level: page + blades must keep Livewire/Alpine wiring that prevents full-page reload.
 */
final class AgentWorkspaceUiHotfixTest extends TestCase
{
    public function test_send_message_and_submit_composer_are_public(): void
    {
        $page = new ReflectionClass(AgentWorkspacePage::class);

        self::assertTrue($page->hasMethod('sendMessage'));
        self::assertTrue($page->hasMethod('submitComposer'));
        self::assertTrue($page->hasMethod('openSkillBrowser'));
        self::assertTrue($page->hasProperty('composerSubmitting'));
        self::assertTrue($page->hasProperty('composerError'));

        self::assertTrue((new ReflectionMethod(AgentWorkspacePage::class, 'sendMessage'))->isPublic());
        self::assertTrue((new ReflectionMethod(AgentWorkspacePage::class, 'submitComposer'))->isPublic());
    }

    public function test_send_message_delegates_to_submit_composer(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspacePage::class))->getFileName(),
        );
        $body = $this->extractMethodBody($source, 'sendMessage');

        self::assertStringContainsString('submitComposer', $body);
    }

    public function test_submit_composer_guards_duplicate_and_whitespace(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspacePage::class))->getFileName(),
        );
        $body = $this->extractMethodBody($source, 'submitComposer');

        self::assertStringContainsString('composerSubmitting', $body);
        self::assertStringContainsString("trim(\$this->composerText)", $body);
        self::assertStringContainsString('messageAccepted', $body);
        self::assertStringContainsString('AgentIntentRouter', $body);
        self::assertStringContainsString('runNaturalLanguagePlanning', $body);
        self::assertStringNotContainsString('CommandBus', $body);
        self::assertStringNotContainsString('redirect(', $body);
        self::assertStringNotContainsString('Redirect::', $body);

        $planning = $this->extractMethodBody($source, 'runNaturalLanguagePlanning');
        self::assertStringContainsString('AgentWorkspaceApplicationService', $planning);
        self::assertStringContainsString('planNaturalLanguage', $planning);
    }

    public function test_composer_blade_prevents_native_navigation(): void
    {
        $path = LegacyAddonPath::resolve('resources/views/components/seo-agent-chat/composer.blade.php');
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('x-on:submit.prevent', $source);
        self::assertStringContainsString('submitAgentComposer()', $source);
        self::assertStringContainsString('onComposerEnter($event)', $source);
        self::assertStringContainsString('composerSubmitting', $source);
        self::assertStringNotContainsString('action="javascript:', $source);

        $page = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/pages/agent-workspace.blade.php'),
        );
        self::assertStringContainsString('shiftKey', $page);
        self::assertStringContainsString('submitAgentComposer', $page);
        self::assertStringContainsString("Alpine.data('seoAgentWorkspace'", $page);
        self::assertStringContainsString('refreshLocalPalette', $page);
        self::assertStringContainsString('paletteOpen', $page);
    }

    public function test_agent_workspace_view_has_single_root_vite_and_no_nested_form_markers(): void
    {
        $shell = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/pages/chat-workspace.blade.php'),
        );
        $source = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/pages/agent-workspace.blade.php'),
        );

        self::assertStringContainsString('<x-filament-panels::page full-height>', $shell);
        self::assertStringContainsString('@vite([', $source);
        self::assertStringContainsString('filament.pages.agent-workspace', $shell);

        self::assertStringContainsString('command-catalog.js', $source);
        self::assertStringContainsString('seo-agent-workspace__palette', $source);
        self::assertStringContainsString('submitAgentComposer', $source);
        self::assertStringContainsString('type="button"', $source);
        self::assertStringNotContainsString('<a href="#"', $source);
        self::assertStringNotContainsString('submit-method=', $source);
    }

    public function test_template_and_skill_cards_are_buttons_not_links(): void
    {
        $path = LegacyAddonPath::resolve('resources/views/filament/pages/agent-workspace.blade.php');
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('seo-agent-workspace__template-card', $source);
        self::assertStringContainsString('seo-agent-workspace__suggestions', $source);
        self::assertStringContainsString('action="selectTemplate"', $source);
        self::assertStringContainsString('onSuggestionPrefilled', $source);
        self::assertStringContainsString('_blockSubmitUntil', $source);
        self::assertStringContainsString('isSubmitBlocked', $source);
        self::assertStringContainsString('agent-suggestion-prefilled', $source);
        self::assertStringContainsString('selectPaletteRow(row)', $source);
        self::assertStringContainsString('filteredCommands', $source);
        self::assertStringContainsString('wire:key="agent-template-', $source);
        self::assertStringContainsString('wire:key="agent-msg-', $source);
        self::assertStringContainsString('AgentCommandCatalog', $source);
        self::assertStringNotContainsString('seo-agent-workspace__template-grid', $source);
        self::assertStringNotContainsString('@js(', $source);
        self::assertStringNotContainsString('Js::from(', $source);
        self::assertStringNotContainsString('@click=', $source);
    }

    public function test_layout_css_locks_viewport_scroll_for_agent_only(): void
    {
        $path = ProjectRoot::addonsPath().'/agent/resources/css/agent-workspace.css';
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('.fi-main:has(.seo-agent-workspace)', $source);
        self::assertStringContainsString('min-height: 0', $source);
        self::assertStringContainsString('.seo-agent-workspace-chat__messages', $source);
        self::assertStringContainsString('overflow-y: auto', $source);
        self::assertStringContainsString('.seo-agent-workspace__suggestions', $source);
        self::assertStringContainsString('.seo-agent-workspace-chat__body', $source);
        self::assertStringContainsString('.seo-agent-workspace__skill-drawer', $source);
        self::assertStringContainsString('> section > div', $source);
        self::assertStringNotContainsString('--seo-agent-header-offset', $source);
        self::assertStringNotContainsString('calc(100dvh -', $source);
        self::assertStringNotContainsString('--seo-agent-header-offset', $this->extractCssBlock($source, '.seo-agent-workspace'));
    }

    /**
     * @return string
     */
    private function extractCssBlock(string $source, string $selector): string
    {
        $pattern = '/'.preg_quote($selector, '/').'\s*\{([^{}]*(?:\{[^{}]*\}[^{}]*)*)\}/s';
        if (! preg_match($pattern, $source, $match)) {
            return '';
        }

        return $match[1];
    }

    public function test_page_does_not_bypass_gateway_or_command_bus_from_ui_entrypoints(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspacePage::class))->getFileName(),
        );

        foreach (['selectSkill', 'selectTemplate', 'sendMessage', 'submitComposer', 'previewSkill', 'confirmSkill'] as $method) {
            if (! $this->methodExistsInSource($source, $method)) {
                continue;
            }
            $body = $this->extractMethodBody($source, $method);
            self::assertStringNotContainsString('ContentProjectCommandBus', $body, $method);
            self::assertStringNotContainsString('gateway->execute', $body, $method);
            self::assertStringNotContainsString('$this->gateway', $body, $method);
        }
    }

    public function test_select_skill_opens_application_service_not_execute(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspacePage::class))->getFileName(),
        );
        $body = $this->extractMethodBody($source, 'selectSkill');

        self::assertStringContainsString('openSkill', $body);
        self::assertStringContainsString('AgentWorkspaceApplicationService', $body);
        self::assertStringNotContainsString('gateway->execute', $body);
        self::assertStringContainsString('requiresConfirmation', $body);
    }

    private function methodExistsInSource(string $source, string $methodName): bool
    {
        return (bool) preg_match('/function\s+'.preg_quote($methodName, '/').'\s*\(/', $source);
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
