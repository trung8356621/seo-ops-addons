<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog;

/**
 * Lightweight Hook summary for Prompt Edit preview (no full compose).
 * Cached by hook_key + hook_version.
 */
class PromptHookSummaryService
{
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    public function __construct(
        private readonly PromptHookEditorCatalog $catalog,
        private readonly PromptHookPresentationService $presentation,
    ) {}

    /**
     * @return array{
     *     hook_key: string,
     *     hook_version: string,
     *     source_path: string,
     *     content_mode: string,
     *     items: list<string>,
     *     pipeline: list<string>
     * }
     */
    public function summarize(string $hookKey, string $hookVersion = ''): array
    {
        $hookKey = trim($hookKey);
        $hookVersion = trim($hookVersion);
        $cacheKey = $hookKey.'@'.($hookVersion !== '' ? $hookVersion : 'latest');

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        if ($hookKey === '') {
            return self::$cache[$cacheKey] = [
                'hook_key' => '',
                'hook_version' => '',
                'source_path' => '',
                'content_mode' => 'none',
                'items' => [],
                'pipeline' => ['Prompt Markdown', 'Final Prompt'],
            ];
        }

        try {
            $definition = $hookVersion !== ''
                ? $this->catalog->find($hookKey, $hookVersion)
                : $this->catalog->latestPinnedOrFail($hookKey);
        } catch (\Throwable) {
            return self::$cache[$cacheKey] = [
                'hook_key' => $hookKey,
                'hook_version' => $hookVersion,
                'source_path' => '',
                'content_mode' => 'unknown',
                'items' => ['Hook template', 'Output rules', 'Runtime variables', 'Validation'],
                'pipeline' => ['Prompt Markdown', 'Hook Template', 'Output Contract', 'Final Prompt'],
            ];
        }

        $version = $definition->version->toString();
        $source = trim((string) ($definition->template['source'] ?? ''));
        $mode = $source !== '' ? $source : 'unknown';
        $items = [];

        if (trim((string) ($definition->outputContract ?? '')) !== '') {
            $items[] = 'Output contract';
        }

        $fields = $definition->inputSchema->fields ?? [];
        if (is_array($fields) && $fields !== []) {
            $items[] = 'Runtime variables';
        }

        $outputValidation = is_array($definition->outputSchema->validation ?? null)
            ? $definition->outputSchema->validation
            : [];
        if ($outputValidation !== [] || ($definition->outputSchema ?? null) !== null) {
            $items[] = 'Validation rules';
        }

        $view = $this->presentation->forHook($hookKey) ?? [];
        $sections = is_array($view['sections'] ?? null) ? $view['sections'] : [];
        $sectionKeys = [];
        foreach ($sections as $section) {
            if (is_array($section) && isset($section['key'])) {
                $sectionKeys[] = (string) $section['key'];
            }
        }
        if (in_array('instructions', $sectionKeys, true) || filled($view['description'] ?? null)) {
            $items[] = 'Formatting';
        }
        if (in_array('notes', $sectionKeys, true)) {
            $items[] = 'Safety rules';
        }

        if ($items === []) {
            $items = ['Hook template', 'Output rules', 'Runtime variables', 'Validation'];
        }

        $manifest = trim((string) ($definition->manifestPath ?? ''));
        $sourcePath = $manifest !== ''
            ? $this->shortenManifestPath($manifest)
            : 'resources/prompt-hooks/v01/'.$hookKey.'@'.$version.'.json';

        return self::$cache[$cacheKey] = [
            'hook_key' => $hookKey,
            'hook_version' => $version,
            'source_path' => $sourcePath,
            'content_mode' => $mode,
            'items' => array_values(array_unique($items)),
            'pipeline' => ['Prompt Markdown', 'Hook Template', 'Output Contract', 'Final Prompt'],
        ];
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    private function shortenManifestPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $marker = '/resources/prompt-hooks/';
        $pos = strpos($normalized, $marker);
        if ($pos === false) {
            return basename($normalized);
        }

        return ltrim(substr($normalized, $pos + 1), '/');
    }
}
