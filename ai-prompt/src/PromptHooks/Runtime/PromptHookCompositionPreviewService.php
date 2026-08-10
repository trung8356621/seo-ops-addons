<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookDefinition;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptProviderCapabilities;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptProviderCapabilityResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptStructuredStrategy;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptHookPresentationService;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;

/**
 * Edit/Test composition preview — same path as ExplicitBinding + RuntimeEngine render/compile.
 * Does not call providers. Keeps {{placeholders}} when values are not supplied.
 *
 * @phpstan-type PreviewSegment array{key: string, label: string, body: string}
 * @phpstan-type CompositionPreview array{
 *     content_mode: string,
 *     final_prompt: string,
 *     segments: list<PreviewSegment>,
 *     unused_markdown: bool,
 *     markdown_preserved: bool
 * }
 */
class PromptHookCompositionPreviewService
{
    public function __construct(
        private readonly PromptHookEditorCatalog $catalog,
        private readonly PromptHookDeterministicTemplateRenderer $templateRenderer,
        private readonly PromptHookRuntimeSettingsResolver $settingsResolver,
        private readonly PromptHookRenderedPromptCompiler $compiler,
        private readonly ?PromptRunnerService $promptRunner = null,
        private readonly PromptProviderCapabilityResolver $capabilityResolver = new PromptProviderCapabilityResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $hookSettings
     * @return CompositionPreview
     */
    public function preview(
        ?string $hookKey,
        ?string $hookVersion,
        string $markdownContent,
        array $hookSettings = [],
    ): array {
        $hookKey = trim((string) $hookKey);
        $markdownContent = (string) $markdownContent;

        if ($hookKey === '') {
            $body = $this->compileMarkdownKeepingPlaceholders($markdownContent);

            return [
                'content_mode' => 'none',
                'final_prompt' => $body,
                'segments' => $body !== '' ? [[
                    'key' => 'prompt_markdown',
                    'label' => $this->t('seo-content-ai::filament.prompt.composed_segment_prompt', 'Prompt content'),
                    'body' => $body,
                ]] : [],
                'unused_markdown' => false,
                'markdown_preserved' => true,
            ];
        }

        $definition = $this->resolveDefinition($hookKey, trim((string) $hookVersion));
        if ($definition === null) {
            return [
                'content_mode' => 'unknown',
                'final_prompt' => '',
                'segments' => [],
                'unused_markdown' => false,
                'markdown_preserved' => true,
            ];
        }

        $source = trim((string) ($definition->template['source'] ?? ''));
        $isLegacy = $source === PromptHookPresentationService::CONTENT_MODE_LEGACY_PROMPT
            || trim((string) ($definition->template['mode'] ?? '')) === PromptHookPresentationService::CONTENT_MODE_LEGACY_PROMPT;

        if ($isLegacy) {
            return $this->previewLegacy($definition, $markdownContent, $hookSettings);
        }

        return $this->previewInline($definition, $markdownContent, $hookSettings);
    }

    public function formatPreviewHtml(array $preview): string
    {
        $segments = $preview['segments'] ?? [];
        if (! is_array($segments) || $segments === []) {
            $final = trim((string) ($preview['final_prompt'] ?? ''));
            if ($final === '') {
                return '<p class="text-sm text-gray-500">'.e($this->t(
                    'seo-content-ai::filament.prompt.composed_preview_empty',
                    'No composed prompt yet.',
                )).'</p>';
            }

            return '<pre class="whitespace-pre-wrap text-sm text-gray-800 dark:text-gray-100 font-mono">'.e($final).'</pre>';
        }

        $blocks = [];
        foreach ($segments as $segment) {
            if (! is_array($segment)) {
                continue;
            }
            $body = trim((string) ($segment['body'] ?? ''));
            if ($body === '') {
                continue;
            }
            $label = e((string) ($segment['label'] ?? ''));
            $blocks[] = '<div class="space-y-1">'
                .'<div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">'.$label.'</div>'
                .'<pre class="whitespace-pre-wrap text-sm text-gray-800 dark:text-gray-100 font-mono rounded-md bg-gray-50 dark:bg-gray-900/40 p-3 border border-gray-200 dark:border-gray-700">'
                .e($body)
                .'</pre></div>';
        }

        $note = '';
        if (($preview['unused_markdown'] ?? false) === true) {
            $note = '<p class="text-sm text-amber-700 dark:text-amber-300">'
                .e($this->t(
                    'seo-content-ai::filament.prompt.composed_unused_markdown_note',
                    'Saved Prompt markdown is kept but is not part of this Hook runtime composition.',
                ))
                .'</p>';
        }

        return $note.'<div class="space-y-4 seo-prompt-composed-preview max-h-[28rem] overflow-y-auto overflow-x-hidden text-sm leading-relaxed break-words whitespace-pre-wrap">'
            .implode('', $blocks)
            .'</div>';
    }

    /**
     * @param  array<string, mixed>  $hookSettings
     * @return CompositionPreview
     */
    private function previewLegacy(
        PromptHookDefinition $definition,
        string $markdownContent,
        array $hookSettings,
    ): array {
        $compiledMarkdown = $this->compileMarkdownKeepingPlaceholders($markdownContent);
        $settings = $this->settingsResolver->resolve($definition, $hookSettings, []);
        $variables = $this->placeholderVariables($definition, $settings['hook']);

        $metadata = [
            'legacy_compiled_prompt' => $compiledMarkdown,
            'variables' => $variables,
        ];

        $request = $this->templateRenderer->render(
            $definition,
            $variables,
            $this->previewLocale(),
            $settings['model'],
            $metadata,
        );

        $strategy = $this->previewStrategy($definition);
        $final = $this->safeCompile($request, $strategy);

        $segments = [];
        if ($compiledMarkdown !== '') {
            $segments[] = [
                'key' => 'prompt_own',
                'label' => $this->t(
                    'seo-content-ai::filament.prompt.composed_segment_prompt_own',
                    'Prompt-specific content',
                ),
                'body' => $compiledMarkdown,
            ];
        }

        $contractOnly = $this->contractDelta($compiledMarkdown, $final);
        if ($contractOnly !== '') {
            $segments[] = [
                'key' => 'hook_contract',
                'label' => $this->t(
                    'seo-content-ai::filament.prompt.composed_segment_hook_default',
                    'Default from Hook',
                ),
                'body' => $contractOnly,
            ];
        }

        $referenceNote = $this->galleryReferenceAttachmentNote($definition);
        if ($referenceNote !== null) {
            $segments[] = [
                'key' => 'reference_attachment',
                'label' => 'Reference image attachment',
                'body' => $referenceNote,
            ];
        }

        if ($segments === [] && $final !== '') {
            $segments[] = [
                'key' => 'final',
                'label' => $this->t('seo-content-ai::filament.prompt.composed_preview_title', 'Composed Prompt Preview'),
                'body' => $final,
            ];
        }

        return [
            'content_mode' => PromptHookPresentationService::CONTENT_MODE_LEGACY_PROMPT,
            'final_prompt' => $final,
            'segments' => $segments,
            'unused_markdown' => false,
            'markdown_preserved' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $hookSettings
     * @return CompositionPreview
     */
    private function previewInline(
        PromptHookDefinition $definition,
        string $markdownContent,
        array $hookSettings,
    ): array {
        $settings = $this->settingsResolver->resolve($definition, $hookSettings, []);
        $variables = $this->placeholderVariables($definition, $settings['hook']);

        $request = $this->templateRenderer->render(
            $definition,
            $variables,
            $this->previewLocale(),
            $settings['model'],
            ['variables' => $variables],
        );

        $strategy = $this->previewStrategy($definition);
        $final = $this->safeCompile($request, $strategy);

        $segments = [];
        if (trim($request->system) !== '') {
            $segments[] = [
                'key' => 'hook_system',
                'label' => $this->t(
                    'seo-content-ai::filament.prompt.composed_segment_hook_default',
                    'Default from Hook',
                ),
                'body' => trim($request->system),
            ];
        }

        foreach ($request->messages as $message) {
            $content = trim((string) ($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $role = strtoupper((string) ($message['role'] ?? 'user'));
            $segments[] = [
                'key' => 'hook_'.$role,
                'label' => $this->t(
                    'seo-content-ai::filament.prompt.composed_segment_hook_template',
                    'Hook template',
                ).' ('.$role.')',
                'body' => $content,
            ];
        }

        return [
            'content_mode' => PromptHookPresentationService::CONTENT_MODE_INLINE,
            'final_prompt' => $final,
            'segments' => $segments !== [] ? $segments : [[
                'key' => 'final',
                'label' => $this->t('seo-content-ai::filament.prompt.composed_preview_title', 'Composed Prompt Preview'),
                'body' => $final,
            ]],
            'unused_markdown' => trim($markdownContent) !== '',
            'markdown_preserved' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $resolvedHookSettings
     * @return array<string, mixed>
     */
    private function placeholderVariables(PromptHookDefinition $definition, array $resolvedHookSettings): array
    {
        $vars = [];
        foreach ($definition->inputSchema->fields as $field => $schema) {
            $vars[(string) $field] = '{{'.$field.'}}';
        }
        foreach ($resolvedHookSettings as $field => $value) {
            // Keep placeholders for structure; settings defaults are runtime values.
            $vars[(string) $field] = '{{'.$field.'}}';
        }

        return $vars;
    }

    /**
     * @return array{locale_code: string, language_name: string}
     */
    private function previewLocale(): array
    {
        return [
            'locale_code' => 'en',
            'language_name' => '{{language}}',
        ];
    }

    private function previewStrategy(PromptHookDefinition $definition): PromptStructuredStrategy
    {
        $capabilities = new PromptProviderCapabilities(
            textGeneration: true,
            jsonMode: true,
            nativeStructuredOutput: false,
            systemMessage: true,
            temperature: true,
            maxTokens: true,
        );

        try {
            return $this->capabilityResolver->resolveStrategy($definition, $capabilities);
        } catch (\Throwable) {
            return PromptStructuredStrategy::PlainText;
        }
    }

    private function safeCompile(RenderedPromptRequest $request, PromptStructuredStrategy $strategy): string
    {
        try {
            return $this->compiler->compile($request, $strategy);
        } catch (\Throwable) {
            return trim((string) ($request->metadata['legacy_compiled_prompt'] ?? ''))
                ?: trim($request->system."\n\n".($request->messages[0]['content'] ?? ''));
        }
    }

    private function compileMarkdownKeepingPlaceholders(string $markdownContent): string
    {
        $markdownContent = trim($markdownContent);
        if ($markdownContent === '') {
            return '';
        }

        $runner = $this->promptRunner;
        if ($runner === null) {
            try {
                if (function_exists('app') && app()->bound(PromptRunnerService::class)) {
                    $runner = app(PromptRunnerService::class);
                }
            } catch (\Throwable) {
                $runner = null;
            }
        }

        if ($runner === null) {
            return $markdownContent;
        }

        $ephemeral = new SeoPrompt([
            'markdown_content' => $markdownContent,
            'tools' => 'default',
        ]);

        try {
            return trim($runner->compileRawPrompt($ephemeral));
        } catch (PromptRunException) {
            return $markdownContent;
        } catch (\Throwable) {
            return $markdownContent;
        }
    }

    private function galleryReferenceAttachmentNote(PromptHookDefinition $definition): ?string
    {
        $meta = $definition->metadata ?? [];
        if (! is_array($meta)) {
            return null;
        }
        if (($meta['reference_attachment'] ?? '') !== 'provider_runtime_inline_data') {
            return null;
        }

        return "Reference image attachment:\n- supplied at provider runtime\n- not embedded in text prompt";
    }

    private function contractDelta(string $before, string $after): string
    {
        $before = trim($before);
        $after = trim($after);
        if ($after === '' || $after === $before) {
            return '';
        }
        if ($before !== '' && str_starts_with($after, $before)) {
            return trim(substr($after, strlen($before)));
        }

        return '';
    }

    private function resolveDefinition(string $hookKey, string $version): ?PromptHookDefinition
    {
        try {
            if ($version === '') {
                return $this->catalog->latestPinnedOrFail($hookKey);
            }

            return $this->catalog->find($hookKey, $version);
        } catch (\Throwable) {
            try {
                return $this->catalog->latestPinnedOrFail($hookKey);
            } catch (\Throwable) {
                return null;
            }
        }
    }

    private function t(string $key, string $fallback): string
    {
        try {
            if (! function_exists('app') || ! app()->bound('translator')) {
                return $fallback;
            }
            $translated = __($key);
            if (is_array($translated)) {
                return $fallback;
            }
            $text = trim((string) $translated);

            return $text !== '' ? $text : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
