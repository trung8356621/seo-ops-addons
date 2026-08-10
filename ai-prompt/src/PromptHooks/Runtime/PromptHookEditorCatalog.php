<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookDefinition;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookStatus;

/**
 * UI catalog for Prompt editor dropdown — sourced from canonical RuntimeRegistry only.
 */
final class PromptHookEditorCatalog
{
    public function __construct(
        private readonly PromptHookRuntimeRegistry $registry,
    ) {}

    /**
     * Hooks with settings_visible=true — Settings capability selectors.
     *
     * @return list<array{
     *   hook_key: string,
     *   version: string,
     *   display_name: string,
     *   description: string,
     *   category: string,
     *   variables: list<string>,
     *   capability: string,
     *   option_label: string
     * }>
     */
    public function settingsVisibleHooks(): array
    {
        $rows = [];
        $seen = [];
        foreach ($this->registry->list() as $definition) {
            if ($definition->status === PromptHookStatus::Disabled) {
                continue;
            }
            if (! $definition->settingsVisible) {
                continue;
            }
            $key = $definition->key->value;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $option = $this->toOption($definition);
            $rows[] = [
                'hook_key' => $option['hook_key'],
                'version' => $option['version'],
                'display_name' => $option['display_name'],
                'description' => $option['description'],
                'category' => $definition->category,
                'variables' => array_keys($definition->inputSchema->fields),
                'capability' => (string) ($definition->model->capability ?? 'text'),
                'option_label' => $option['option_label'],
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['display_name'], $b['display_name']));

        return $rows;
    }

    public function isSettingsVisible(string $hookKey): bool
    {
        $hookKey = trim($hookKey);
        if ($hookKey === '') {
            return false;
        }

        foreach ($this->settingsVisibleHooks() as $row) {
            if ($row['hook_key'] === $hookKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * Text-capable hooks for Prompt blocks (not image/video).
     * Phase 1.0: exclude legacy rewrite — không cho chọn khi tạo/gán mới.
     *
     * @return list<array{
     *   hook_key: string,
     *   version: string,
     *   display_name: string,
     *   description: string,
     *   status: string,
     *   experimental: bool,
     *   output_type: string,
     *   input_summary: string,
     *   option_label: string
     * }>
     */
    public function optionsForTextPromptBlock(): array
    {
        $rows = [];
        foreach ($this->registry->list() as $definition) {
            if ($definition->status === PromptHookStatus::Disabled) {
                continue;
            }
            if (($definition->model->capability ?? 'text') !== 'text') {
                continue;
            }
            if ($this->isLegacyCompatibilityHook($definition->key->value)) {
                continue;
            }
            $rows[] = $this->toOption($definition);
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['display_name'], $b['display_name']));

        return $rows;
    }

    /**
     * @return array<string, string> hook_key => option label
     */
    public function selectOptions(): array
    {
        $options = [];
        $seen = [];
        foreach ($this->registry->list() as $definition) {
            if ($definition->status === PromptHookStatus::Disabled) {
                continue;
            }
            $key = $definition->key->value;
            if ($this->isLegacyCompatibilityHook($key)) {
                continue;
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $row = $this->toOption($definition);
            $options[$row['hook_key']] = $row['option_label'];
        }

        return $options;
    }

    /**
     * Select options khi edit Prompt đã gắn legacy rewrite — giữ giá trị hiện tại + badge Legacy.
     *
     * @return array<string, string>
     */
    public function selectOptionsForEditing(string $currentHookKey = ''): array
    {
        $options = $this->selectOptions();
        $current = trim($currentHookKey);
        if ($current !== '' && $this->isLegacyCompatibilityHook($current) && ! isset($options[$current])) {
            $options[$current] = $this->labelWithHookKey('Rewrite article content (Legacy)', $current);
        }

        return $options;
    }

    /**
     * DEPRECATED COMPATIBILITY ONLY — không assign cho Prompt/Settings mới.
     */
    public function isLegacyCompatibilityHook(string $hookKey): bool
    {
        return trim($hookKey) === 'article.content.rewrite';
    }

    public function find(string $hookKey, string $version): PromptHookDefinition
    {
        return $this->registry->get($hookKey, $version);
    }

    public function latestPinnedOrFail(string $hookKey): PromptHookDefinition
    {
        $matches = array_values(array_filter(
            $this->registry->list(),
            static fn (PromptHookDefinition $d): bool => $d->key->value === $hookKey
                && $d->status !== PromptHookStatus::Disabled,
        ));
        if ($matches === []) {
            throw new \InvalidArgumentException("Hook [{$hookKey}] not found in editor catalog.");
        }

        // Prefer explicit 0.1.0 then highest semver string sort for this slice.
        usort(
            $matches,
            static fn (PromptHookDefinition $a, PromptHookDefinition $b): int => version_compare(
                $b->version->toString(),
                $a->version->toString(),
            ),
        );

        return $matches[0];
    }

    /**
     * @return array{
     *   hook_key: string,
     *   version: string,
     *   display_name: string,
     *   description: string,
     *   status: string,
     *   experimental: bool,
     *   output_type: string,
     *   input_summary: string,
     *   option_label: string
     * }
     */
    private function toOption(PromptHookDefinition $definition): array
    {
        $display = $this->displayName($definition);
        $experimental = $definition->status === PromptHookStatus::Experimental;
        $badge = $experimental
            ? ' ('.$this->safeTranslate('seo-content-ai::prompt_hooks.experimental_badge', 'experimental').')'
            : '';

        return [
            'hook_key' => $definition->key->value,
            'version' => $definition->version->toString(),
            'display_name' => $display,
            'description' => $this->description($definition),
            'status' => $definition->status->value,
            'experimental' => $experimental,
            'output_type' => $definition->outputSchema->type,
            'input_summary' => $this->inputSummary($definition),
            // Phase 1.0: hiện hook_key dạng [code] để khớp doctor / Stable Gate.
            'option_label' => $display.$badge.' ['.$definition->key->value.']',
        ];
    }

    /**
     * Label Settings section / UI: «Improve article content [article.content.improve]».
     */
    public function labelWithHookKey(string $displayName, string $hookKey): string
    {
        $display = trim($displayName);
        $key = trim($hookKey);
        if ($key === '') {
            return $display;
        }
        if ($display === '' || str_contains($display, '['.$key.']')) {
            return $display !== '' ? $display : '['.$key.']';
        }

        return $display.' ['.$key.']';
    }

    private function displayName(PromptHookDefinition $definition): string
    {
        $fallback = $definition->name !== '' ? $definition->name : $definition->key->value;
        $langKey = 'seo-content-ai::prompt_hooks.'.$this->langSlug($definition->key->value).'.label';
        $translated = $this->safeTranslate($langKey, $fallback);
        if ($translated !== $langKey && $translated !== '') {
            return $translated;
        }

        return $fallback;
    }

    private function description(PromptHookDefinition $definition): string
    {
        $fallback = $definition->description;
        $langKey = 'seo-content-ai::prompt_hooks.'.$this->langSlug($definition->key->value).'.description';
        $translated = $this->safeTranslate($langKey, $fallback);
        if ($translated !== $langKey && $translated !== '') {
            return $translated;
        }

        // Phase 1 dual-read may store label_key as name
        if (str_starts_with($definition->description, 'prompt_hooks.')) {
            return $this->safeTranslate('seo-content-ai::'.$definition->description, $definition->description);
        }

        return $definition->description;
    }

    /**
     * Pure PHPUnit (no Laravel app) không có binding `translator` — fallback name/description.
     */
    private function safeTranslate(string $key, string $fallback): string
    {
        try {
            if (! function_exists('app') || ! app()->bound('translator')) {
                return $fallback !== '' ? $fallback : $key;
            }

            $translated = (string) __($key);

            return $translated !== '' ? $translated : ($fallback !== '' ? $fallback : $key);
        } catch (\Throwable) {
            return $fallback !== '' ? $fallback : $key;
        }
    }

    private function inputSummary(PromptHookDefinition $definition): string
    {
        $required = [];
        $optional = [];
        foreach ($definition->inputSchema->fields as $field => $schema) {
            if (! is_array($schema)) {
                continue;
            }
            if (($schema['required'] ?? false) === true) {
                $required[] = (string) $field;
            } else {
                $optional[] = (string) $field;
            }
        }

        return 'required=['.implode(',', $required).'] optional=['.implode(',', $optional).']';
    }

    private function langSlug(string $hookKey): string
    {
        return str_replace('.', '_', $hookKey);
    }
}
