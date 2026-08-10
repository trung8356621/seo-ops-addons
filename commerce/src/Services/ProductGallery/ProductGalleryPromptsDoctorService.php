<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductGallery;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultProductGalleryPromptsInstaller;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\AiPrompt\Support\ProductGallery\ProductGalleryPromptVariableNormalizer;

/**
 * Read-only health check for Mode 2 Prompt Hook bindings.
 */
final class ProductGalleryPromptsDoctorService
{
    /** @var list<string> */
    public const HOOKS = [
        DefaultProductGalleryPromptsInstaller::HOOK_PLAN,
        DefaultProductGalleryPromptsInstaller::HOOK_PARENT,
        DefaultProductGalleryPromptsInstaller::HOOK_CHILD,
    ];

    public function __construct(
        private readonly PromptHookRuntimeRegistry $registry,
        private readonly SeoCreateArticleSettingsService $settings,
        private readonly PromptRunnerService $promptRunner,
        private readonly ProductGalleryPlanParser $planParser,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     lines: list<array{label: string, status: string, detail: string}>,
     *     errors: list<string>
     * }
     */
    public function diagnose(): array
    {
        $lines = [];
        $errors = [];

        foreach (self::HOOKS as $hookKey) {
            $check = $this->checkHook($hookKey);
            $lines[] = [
                'label' => $hookKey,
                'status' => $check['ok'] ? 'OK' : 'FAIL',
                'detail' => $check['detail'],
            ];
            if (! $check['ok']) {
                $errors[] = $hookKey.': '.$check['detail'];
            }
        }

        $fallbackOk = GeminiProductGalleryParentChildAiAdapter::FALLBACK_BRIEF_ENABLED === false;
        $lines[] = [
            'label' => 'fallback brief',
            'status' => $fallbackOk ? 'DISABLED' : 'ACTIVE',
            'detail' => $fallbackOk ? 'live adapter hard-fails on missing Hook' : 'FALLBACK_BRIEF_ENABLED=true',
        ];
        if (! $fallbackOk) {
            $errors[] = 'fallback brief still active';
        }

        $compile = $this->checkRuntimeCompile();
        $lines[] = [
            'label' => 'runtime compile',
            'status' => $compile['ok'] ? 'OK' : 'FAIL',
            'detail' => $compile['detail'],
        ];
        if (! $compile['ok']) {
            $errors[] = 'runtime compile: '.$compile['detail'];
        }

        $contract = $this->checkPlannerContract();
        $lines[] = [
            'label' => 'planner output contract',
            'status' => $contract['ok'] ? 'OK' : 'FAIL',
            'detail' => $contract['detail'],
        ];
        if (! $contract['ok']) {
            $errors[] = 'planner contract: '.$contract['detail'];
        }

        return [
            'ok' => $errors === [],
            'lines' => $lines,
            'errors' => $errors,
        ];
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function checkHook(string $hookKey): array
    {
        if (! $this->registry->has($hookKey, ProductGalleryPromptHookRuntime::VERSION)) {
            return ['ok' => false, 'detail' => 'hook definition missing'];
        }

        $definition = $this->registry->get($hookKey, ProductGalleryPromptHookRuntime::VERSION);
        foreach (ProductGalleryPromptVariableNormalizer::requiredKeysForHook($hookKey) as $key) {
            if (! isset($definition->inputSchema->fields[$key])) {
                return ['ok' => false, 'detail' => 'hook input_schema missing field: '.$key];
            }
        }

        $promptId = $this->settings->getBoundPromptId($hookKey);
        if ($promptId === null) {
            return ['ok' => false, 'detail' => 'prompt_hook_binding_missing'];
        }

        $prompt = SeoPrompt::query()->find($promptId);
        if (! $prompt instanceof SeoPrompt) {
            return ['ok' => false, 'detail' => 'prompt_not_found #'.$promptId];
        }

        $promptHook = trim((string) ($prompt->hook_key ?? ''));
        if ($promptHook !== '' && $promptHook !== $hookKey) {
            return ['ok' => false, 'detail' => 'prompt hook mismatch (prompt.hook_key='.$promptHook.')'];
        }

        $markdown = (string) ($prompt->markdown_content ?? '');
        if (trim($markdown) === '') {
            return ['ok' => false, 'detail' => 'prompt markdown empty'];
        }

        foreach ($this->requiredPlaceholders($hookKey) as $placeholder) {
            if (! str_contains($markdown, '{{ '.$placeholder.' }}') && ! str_contains($markdown, '{{'.$placeholder.'}}')) {
                return ['ok' => false, 'detail' => 'prompt_variable_missing in markdown: '.$placeholder];
            }
        }

        if ($hookKey !== DefaultProductGalleryPromptsInstaller::HOOK_PLAN) {
            if (! str_contains($markdown, 'inlineData') && ! str_contains(strtolower($markdown), 'provider runtime')) {
                // Soft note only — markdown templates include attachment note.
            }
        }

        return ['ok' => true, 'detail' => 'prompt #'.$promptId];
    }

    /**
     * @return list<string>
     */
    private function requiredPlaceholders(string $hookKey): array
    {
        return match ($hookKey) {
            DefaultProductGalleryPromptsInstaller::HOOK_PLAN => [
                'product_title',
                'requested_image_count',
                'negative_constraints',
            ],
            DefaultProductGalleryPromptsInstaller::HOOK_PARENT => [
                'product_title',
                'negative_constraints',
            ],
            DefaultProductGalleryPromptsInstaller::HOOK_CHILD => [
                'product_title',
                'shot_key',
                'shot_instruction',
                'negative_constraints',
            ],
            default => [],
        };
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function checkRuntimeCompile(): array
    {
        $samples = [];
        foreach (self::HOOKS as $hookKey) {
            $promptId = $this->settings->getBoundPromptId($hookKey);
            if ($promptId === null) {
                return ['ok' => false, 'detail' => 'missing binding for '.$hookKey];
            }
            $prompt = SeoPrompt::query()->find($promptId);
            if (! $prompt instanceof SeoPrompt) {
                return ['ok' => false, 'detail' => 'prompt_not_found for '.$hookKey];
            }

            $vars = ProductGalleryPromptVariableNormalizer::sampleForHook($hookKey);
            $stringVars = [];
            foreach ($vars as $k => $v) {
                $stringVars[$k] = is_scalar($v) ? (string) $v : '';
            }

            try {
                $compiled = trim($this->promptRunner->compilePrompt($prompt, $stringVars));
            } catch (\Throwable $exception) {
                return ['ok' => false, 'detail' => $hookKey.' compile error: '.$exception->getMessage()];
            }

            if ($compiled === '') {
                return ['ok' => false, 'detail' => $hookKey.' compiled empty'];
            }
            if (str_contains($compiled, 'data:image/') || preg_match('/\b[A-Za-z0-9+\/=]{800,}\b/', $compiled) === 1) {
                return ['ok' => false, 'detail' => $hookKey.' binary leaked into text'];
            }
            if (str_contains($compiled, '{{ product_title }}') || str_contains($compiled, '{{product_title}}')) {
                return ['ok' => false, 'detail' => $hookKey.' unresolved product_title'];
            }

            $samples[$hookKey] = mb_substr($compiled, 0, 80);
        }

        return ['ok' => true, 'detail' => 'sample ok ('.count($samples).' hooks)'];
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function checkPlannerContract(): array
    {
        $valid = '{"shots":[{"slot":1,"shot_key":"front","label":"Mặt trước","priority":"required","aspect_ratio":"1:1","instruction":"Front view"}]}';
        $parsed = $this->planParser->parse($valid, 3);
        if (! ($parsed['ok'] ?? false)) {
            return ['ok' => false, 'detail' => 'valid sample rejected: '.implode(',', $parsed['errors'] ?? [])];
        }

        $invalid = '{"shots":[{"slot":1,"shot_key":"front","label":"x","priority":"nope","aspect_ratio":"1:1","instruction":"x"}]}';
        $bad = $this->planParser->parse($invalid, 3);
        if ($bad['ok'] ?? false) {
            return ['ok' => false, 'detail' => 'invalid priority accepted'];
        }

        return ['ok' => true, 'detail' => 'parser rejects invalid priority'];
    }
}
