<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use RuntimeException;

/**
 * Idempotent default Prompt + Settings binding for keyword.discovery.structured.
 * Canonical default body is loaded from Hook JSON canonical_default.markdown (SSOT).
 * Does not overwrite operator-edited markdown or existing bindings unless restore is requested.
 */
final class DefaultKeywordDiscoveryPromptInstaller
{
    public const HOOK_KEY = 'keyword.discovery.structured';

    public const HOOK_VERSION = '0.1.0';

    public const PROMPT_NAME = 'Keyword Discovery';

    public function __construct(
        private readonly SeoCreateArticleSettingsService $settings,
    ) {}

    /**
     * @return array{prompt_id: int, created: bool, binding_set: bool, restored: bool}
     */
    public function install(bool $restoreCanonical = false): array
    {
        $existing = SeoPrompt::query()
            ->where('hook_key', self::HOOK_KEY)
            ->where('name', self::PROMPT_NAME)
            ->orderBy('id')
            ->first();

        $created = false;
        $restored = false;

        if ($existing === null) {
            $existing = new SeoPrompt;
            $existing->fill([
                'name' => self::PROMPT_NAME,
                'title' => self::PROMPT_NAME,
                'markdown_content' => self::canonicalDefaultMarkdown(),
                'description' => self::canonicalDescription(),
                'hook_key' => self::HOOK_KEY,
                'hook_version' => self::HOOK_VERSION,
                'variables' => self::canonicalVariables(),
                'tools' => 'default',
                'is_active' => true,
                'user_id' => $this->systemUserId(),
                'settings' => [
                    'is_system_default' => true,
                    'ownership' => 'settings_binding',
                ],
            ]);
            $existing->save();
            $created = true;
        } elseif ($restoreCanonical) {
            $existing->markdown_content = self::canonicalDefaultMarkdown();
            $existing->description = self::canonicalDescription();
            $existing->variables = self::canonicalVariables();
            $existing->hook_version = self::HOOK_VERSION;
            $existing->save();
            $restored = true;
        }

        $promptId = (int) $existing->id;
        $bindings = $this->settings->getPromptHookBindings();
        $bindingSet = false;
        if (! isset($bindings[self::HOOK_KEY])) {
            $this->settings->savePromptHookBindings([
                self::HOOK_KEY => $promptId,
            ]);
            $bindingSet = true;
        }

        return [
            'prompt_id' => $promptId,
            'created' => $created,
            'binding_set' => $bindingSet,
            'restored' => $restored,
        ];
    }

    /**
     * Editable Prompt Markdown from Hook JSON SSOT (canonical_default.markdown).
     */
    public static function canonicalDefaultMarkdown(): string
    {
        $spec = self::loadCanonicalSpec();
        $block = is_array($spec['canonical_default'] ?? null) ? $spec['canonical_default'] : [];
        $markdown = trim((string) ($block['markdown'] ?? ''));
        if ($markdown === '') {
            throw new RuntimeException('Canonical Keyword Discovery markdown is empty.');
        }

        return $markdown;
    }

    public static function canonicalDescription(): string
    {
        $spec = self::loadCanonicalSpec();
        $presentation = is_array($spec['presentation'] ?? null) ? $spec['presentation'] : [];
        $fromPresentation = trim((string) ($presentation['description'] ?? ''));
        if ($fromPresentation !== '') {
            return $fromPresentation;
        }

        return trim((string) ($spec['description'] ?? 'Generate structured topic and article ideas for Content Planning.'));
    }

    /**
     * @return list<array{name: string, description: string}>
     */
    public static function canonicalVariables(): array
    {
        $spec = self::loadCanonicalSpec();
        $schema = is_array($spec['input_schema'] ?? null) ? $spec['input_schema'] : [];
        $rows = [];
        foreach ($schema as $key => $field) {
            $name = trim((string) $key);
            if ($name === '') {
                continue;
            }
            $label = is_array($field) ? trim((string) ($field['label'] ?? $name)) : $name;
            $rows[] = [
                'name' => $name,
                'description' => $label !== '' ? $label : $name,
            ];
        }

        return $rows !== [] ? $rows : [
            ['name' => 'seed_topic', 'description' => 'Seed topic'],
            ['name' => 'count', 'description' => 'Requested quantity'],
            ['name' => 'brief', 'description' => 'Planning brief / notes'],
            ['name' => 'primary_language', 'description' => 'Effective primary language'],
            ['name' => 'content_type', 'description' => 'Content type (post|product)'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function loadCanonicalSpec(): array
    {
        $path = PromptHookDefinitionLoader::defaultV01Directory()
            .DIRECTORY_SEPARATOR
            .self::HOOK_KEY.'@'.self::HOOK_VERSION.'.json';
        if (! is_file($path)) {
            throw new RuntimeException('Canonical Keyword Discovery Hook JSON missing: '.$path);
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Canonical Keyword Discovery Hook JSON is invalid.');
        }

        return $decoded;
    }

    private function systemUserId(): int
    {
        $authId = auth()->id();
        if ($authId !== null && (int) $authId > 0) {
            return (int) $authId;
        }

        try {
            $id = \App\Models\User::query()->orderBy('id')->value('id');
            if ($id !== null && (int) $id > 0) {
                return (int) $id;
            }
        } catch (\Throwable) {
            // fall through
        }

        return 1;
    }
}
