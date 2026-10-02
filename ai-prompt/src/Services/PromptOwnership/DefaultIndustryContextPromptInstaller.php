<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use RuntimeException;

final class DefaultIndustryContextPromptInstaller
{
    public const HOOK_KEY = 'industry.context.generate';

    public const HOOK_VERSION = '0.1.0';

    public const PROMPT_NAME = 'Industry Context Generator';

    /** @var array<string, array{hook:string,name:string}> */
    private const TYPES = [
        'core' => ['hook' => self::HOOK_KEY, 'name' => self::PROMPT_NAME],
        'discovery' => ['hook' => 'industry.discovery.generate', 'name' => 'Industry Discovery & Attention Generator'],
        'breakout' => ['hook' => 'industry.breakout.generate', 'name' => 'Industry Breakout Generator'],
        'match' => ['hook' => 'industry.match.generate', 'name' => 'Industry Match & Research Generator'],
    ];

    public function __construct(private readonly SeoCreateArticleSettingsService $settings) {}

    /** @return array{prompt_id:int,created:bool,binding_set:bool,restored:bool} */
    public function install(bool $restoreCanonical = false): array
    {
        return $this->installType('core', $restoreCanonical);
    }

    /** @return array{prompt_id:int,created:bool,binding_set:bool,restored:bool} */
    public function installType(string $type, bool $restoreCanonical = false): array
    {
        $config = self::TYPES[$type] ?? throw new RuntimeException("Unknown Industry Context prompt type [{$type}].");
        $prompt = SeoPrompt::query()->where('hook_key', $config['hook'])->where('name', $config['name'])->orderBy('id')->first();
        $created = false;
        $restored = false;
        if ($prompt === null) {
            $prompt = new SeoPrompt;
            $prompt->fill(['name' => $config['name'], 'title' => $config['name'], 'markdown_content' => self::canonicalDefaultMarkdown($type), 'description' => self::canonicalDescription($type), 'hook_key' => $config['hook'], 'hook_version' => self::HOOK_VERSION, 'variables' => self::canonicalVariables($type), 'tools' => 'default', 'is_active' => true, 'user_id' => $this->systemUserId(), 'settings' => ['is_system_default' => true, 'ownership' => 'settings_binding']]);
            $prompt->save();
            $created = true;
        } elseif ($restoreCanonical) {
            $prompt->fill(['markdown_content' => self::canonicalDefaultMarkdown($type), 'description' => self::canonicalDescription($type), 'variables' => self::canonicalVariables($type), 'hook_version' => self::HOOK_VERSION])->save();
            $restored = true;
        }
        $bindings = $this->settings->getPromptHookBindings();
        $bindingSet = false;
        if (! isset($bindings[$config['hook']])) {
            $this->settings->savePromptHookBindings([$config['hook'] => (int) $prompt->id]);
            $bindingSet = true;
        }

        return ['prompt_id' => (int) $prompt->id, 'created' => $created, 'binding_set' => $bindingSet, 'restored' => $restored];
    }

    public static function canonicalDefaultMarkdown(string $type = 'core'): string
    {
        $block = self::spec($type)['canonical_default'] ?? [];
        $value = is_array($block) ? trim((string) ($block['markdown'] ?? '')) : '';
        if ($value === '') {
            throw new RuntimeException('Canonical Industry Context prompt is empty.');
        }

        return $value;
    }

    public static function canonicalDescription(string $type = 'core'): string
    {
        return (string) (self::spec($type)['description'] ?? 'Industry Context generator.');
    }

    /** @return list<array{name:string,description:string}> */
    public static function canonicalVariables(string $type = 'core'): array
    {
        $rows = [];
        foreach ((array) (self::spec($type)['input_schema'] ?? []) as $name => $field) {
            $rows[] = ['name' => (string) $name, 'description' => (string) ((array) $field)['label']];
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    private static function spec(string $type = 'core'): array
    {
        $config = self::TYPES[$type] ?? throw new RuntimeException("Unknown Industry Context prompt type [{$type}].");
        $path = PromptHookDefinitionLoader::defaultV01Directory().DIRECTORY_SEPARATOR.$config['hook'].'@'.self::HOOK_VERSION.'.json';
        $value = json_decode((string) file_get_contents($path), true);
        if (! is_array($value)) {
            throw new RuntimeException('Canonical Industry Context Hook JSON is invalid.');
        }

        return $value;
    }

    private function systemUserId(): int
    {
        $authId = auth()->id();
        if ($authId !== null && (int) $authId > 0) {
            return (int) $authId;
        }
        try {
            return (int) (\App\Models\User::query()->orderBy('id')->value('id') ?: 1);
        } catch (\Throwable) {
            return 1;
        }
    }
}
