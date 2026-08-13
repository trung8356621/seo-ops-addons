<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * Render-contract tests: dynamic keys must live in value/data attributes,
 * never inside Alpine/Livewire JS expression strings.
 */
final class AgentWorkspaceInteractionBindingTest extends TestCase
{
    public function test_action_button_uses_static_expression_and_value_attribute(): void
    {
        $path = LegacyAddonPath::resolve('resources/views/components/agent-workspace/action-button.blade.php');
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('value="{{ $reference }}"', $source);
        self::assertStringContainsString('wire:click.prevent="selectSkill($event.currentTarget.value)"', $source);
        self::assertStringContainsString('wire:click.prevent="selectTemplate($event.currentTarget.value)"', $source);
        self::assertStringContainsString('wire:click.prevent="selectCommand($event.currentTarget.value)"', $source);
        self::assertStringNotContainsString('@js(', $source);
        self::assertStringNotContainsString('Js::from', $source);
        self::assertStringNotContainsString('x-on:click="$wire.', $source);
        self::assertStringNotContainsString('wire:click="{{', $source);
        self::assertStringNotContainsString('wire:click.prevent="{{', $source);
    }

    public function test_agent_workspace_blades_have_no_dynamic_js_injection(): void
    {
        $addonViews = ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views';
        $roots = [
            $addonViews.'/filament/pages/agent-workspace.blade.php',
            $addonViews.'/filament/pages/partials/agent-context-panel.blade.php',
            $addonViews.'/filament/pages/partials/agent-conversation-list.blade.php',
            $addonViews.'/filament/pages/partials/agent-execution-card.blade.php',
            $addonViews.'/filament/pages/partials/agent-message.blade.php',
            $addonViews.'/filament/pages/partials/agent-message-structured.blade.php',
            $addonViews.'/filament/pages/partials/agent-skill-form.blade.php',
            $addonViews.'/filament/pages/partials/agent-workspace',
            $addonViews.'/components/seo-agent-chat',
            $addonViews.'/components/agent-workspace',
        ];

        $files = [];
        foreach ($roots as $root) {
            if (is_file($root)) {
                $files[] = $root;
                continue;
            }
            if (! is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }
                $files[] = $file->getPathname();
            }
        }

        self::assertNotSame([], $files);

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $label = str_replace('\\', '/', $file);
            self::assertStringNotContainsString('@js(', $source, 'Forbidden @js( in '.$label);
            self::assertStringNotContainsString('Js::from(', $source, 'Forbidden Js::from( in '.$label);
            self::assertDoesNotMatchRegularExpression(
                '/wire:click\s*=\s*[\'"][^\'"]*\{\{/',
                $source,
                'Dynamic Blade inside wire:click: '.$label,
            );
            self::assertDoesNotMatchRegularExpression(
                '/x-on:click\s*=\s*[\'"][^\'"]*\{\{/',
                $source,
                'Dynamic Blade inside x-on:click: '.$label,
            );
            self::assertDoesNotMatchRegularExpression(
                '/\sx-bind:class\s*=\s*[\'"]\s*\{/',
                $source,
                'Object literal x-bind:class: '.$label,
            );
            self::assertDoesNotMatchRegularExpression('/\s@click(\.|=)/m', $source, 'Forbidden @click in '.$label);
        }
    }

    public function test_simulated_rendered_action_button_html_is_safe(): void
    {
        $keys = [
            'normal-key',
            'key.with.dots',
            'key-with-hyphen',
            'keyword-opportunities',
            'content_project.create',
            "label'with-quote",
            'label"with-double',
        ];

        foreach ($keys as $key) {
            $html = $this->renderActionButtonHtml('selectTemplate', $key, 'Card '.$key);
            $dom = new DOMDocument();
            @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
            $xpath = new DOMXPath($dom);
            /** @var DOMElement|null $button */
            $button = $xpath->query('//button')->item(0);
            self::assertInstanceOf(DOMElement::class, $button, $key);

            $value = $button->getAttribute('value');
            $wireClick = $button->getAttribute('wire:click.prevent');
            if ($wireClick === '') {
                $wireClick = $button->getAttribute('wire:click');
            }

            self::assertSame($key, html_entity_decode($value, ENT_QUOTES));
            self::assertSame('selectTemplate($event.currentTarget.value)', $wireClick);
            self::assertStringNotContainsString($key, $wireClick);
            self::assertStringNotContainsString('@js', $wireClick);
            self::assertFalse($button->hasAttribute('x-on:click'));
            self::assertSame('button', $button->getAttribute('type'));
        }
    }

    public function test_page_exposes_select_command_and_send_message_with_argument(): void
    {
        $pagePath = ProjectRoot::addonsPath().'/agent/src/Filament/Pages/AgentWorkspacePage.php';
        self::assertFileExists($pagePath, 'Deploy AgentWorkspacePage.php to remote host');

        $source = (string) file_get_contents($pagePath);

        self::assertMatchesRegularExpression('/function\s+selectCommand\s*\(/', $source, 'Missing selectCommand — upload AgentWorkspacePage.php');
        self::assertMatchesRegularExpression('/function\s+sendMessage\s*\(\s*\?string\s+\$message\s*=\s*null/', $source, 'Missing sendMessage(?string $message = null, ...)');
        self::assertMatchesRegularExpression('/function\s+normalizeAgentReference\s*\(/', $source);
        self::assertMatchesRegularExpression('/function\s+pollActiveExecutions\s*\(/', $source);
        self::assertStringContainsString('strlen($value) > $maxLength', $source);
    }

    public function test_composer_has_single_submit_owner(): void
    {
        $path = ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/components/seo-agent-chat/composer.blade.php';
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('x-on:submit.prevent="submitAgentComposer()"', $source);
        self::assertStringContainsString('x-model="composer"', $source);
        self::assertStringContainsString('type="submit"', $source);
        self::assertStringNotContainsString('wire:submit', $source);
        self::assertStringNotContainsString('wire:click', $source);
        self::assertDoesNotMatchRegularExpression('/\bwire:model(?:\.[\w.-]+)?\s*=/', $source);
        self::assertStringContainsString('wire:target="selectTemplate"', $source);
    }

    public function test_no_dual_wire_and_alpine_click_on_action_button(): void
    {
        $path = LegacyAddonPath::resolve('resources/views/components/agent-workspace/action-button.blade.php');
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('wire:click.prevent=', $source);
        self::assertStringNotContainsString('x-on:click=', $source);
        self::assertStringNotContainsString('onclick=', $source);
    }

    private function renderActionButtonHtml(string $action, string $value, string $label): string
    {
        $clickExpression = match ($action) {
            'selectSkill' => 'selectSkill($event.currentTarget.value)',
            'selectTemplate' => 'selectTemplate($event.currentTarget.value)',
            'selectCommand' => 'selectCommand($event.currentTarget.value)',
            default => 'selectSkill($event.currentTarget.value)',
        };

        return '<button type="button" value="'.htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"'
            .' wire:click="'.htmlspecialchars($clickExpression, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'
            .htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            .'</button>';
    }
}
