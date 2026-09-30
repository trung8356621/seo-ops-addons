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

    public function __construct(private readonly SeoCreateArticleSettingsService $settings) {}

    /** @return array{prompt_id:int,created:bool,binding_set:bool,restored:bool} */
    public function install(bool $restoreCanonical = false): array
    {
        $prompt = SeoPrompt::query()->where('hook_key', self::HOOK_KEY)->where('name', self::PROMPT_NAME)->orderBy('id')->first();
        $created = false;
        $restored = false;
        if ($prompt === null) {
            $prompt = new SeoPrompt;
            $prompt->fill(['name' => self::PROMPT_NAME, 'title' => self::PROMPT_NAME, 'markdown_content' => self::canonicalDefaultMarkdown(), 'description' => self::canonicalDescription(), 'hook_key' => self::HOOK_KEY, 'hook_version' => self::HOOK_VERSION, 'variables' => self::canonicalVariables(), 'tools' => 'default', 'is_active' => true, 'user_id' => $this->systemUserId(), 'settings' => ['is_system_default' => true, 'ownership' => 'settings_binding']]);
            $prompt->save();
            $created = true;
        } elseif ($restoreCanonical) {
            $prompt->fill(['markdown_content' => self::canonicalDefaultMarkdown(), 'description' => self::canonicalDescription(), 'variables' => self::canonicalVariables(), 'hook_version' => self::HOOK_VERSION])->save();
            $restored = true;
        }
        $bindings = $this->settings->getPromptHookBindings();
        $bindingSet = false;
        if (! isset($bindings[self::HOOK_KEY])) {
            $this->settings->savePromptHookBindings([self::HOOK_KEY => (int) $prompt->id]);
            $bindingSet = true;
        }

        return ['prompt_id' => (int) $prompt->id, 'created' => $created, 'binding_set' => $bindingSet, 'restored' => $restored];
    }

    public static function canonicalDefaultMarkdown(): string
    {
        $block = self::spec()['canonical_default'] ?? [];
        $value = is_array($block) ? trim((string) ($block['markdown'] ?? '')) : '';
        if ($value === '') {
            throw new RuntimeException('Canonical Industry Context prompt is empty.');
        }

        return $value;
    }

    public static function canonicalDescription(): string
    {
        return (string) (self::spec()['description'] ?? 'Industry Context generator.');
    }

    /** @return list<array{name:string,description:string}> */
    public static function canonicalVariables(): array
    {
        $rows = [];
        foreach ((array) (self::spec()['input_schema'] ?? []) as $name => $field) {
            $rows[] = ['name' => (string) $name, 'description' => (string) ((array) $field)['label']];
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    private static function spec(): array
    {
        $path = PromptHookDefinitionLoader::defaultV01Directory().DIRECTORY_SEPARATOR.self::HOOK_KEY.'@'.self::HOOK_VERSION.'.json';
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
