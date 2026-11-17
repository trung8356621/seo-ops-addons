<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use RuntimeException;

/**
 * Idempotent default Prompt + Settings binding for site.cta.templates.generate.
 */
final class DefaultSiteCtaTemplatesPromptInstaller
{
    public const HOOK_KEY = 'site.cta.templates.generate';

    public const HOOK_VERSION = '0.2.0';

    public const PROMPT_NAME = 'Site CTA Template Pool Generator';

    public function __construct(
        private readonly SeoCreateArticleSettingsService $settings,
    ) {}

    /**
     * @return array{prompt_id: int, created: bool, binding_set: bool}
     */
    public function install(): array
    {
        $existing = SeoPrompt::query()
            ->where('hook_key', self::HOOK_KEY)
            ->where('name', self::PROMPT_NAME)
            ->orderBy('id')
            ->first();

        $created = false;
        if ($existing === null) {
            $existing = new SeoPrompt;
            $existing->fill([
                'name' => self::PROMPT_NAME,
                'title' => self::PROMPT_NAME,
                'markdown_content' => self::canonicalDefaultMarkdown(),
                'description' => 'Append exactly 10 new Site CTA templates from MCP Site Context.',
                'hook_key' => self::HOOK_KEY,
                'hook_version' => self::HOOK_VERSION,
                'variables' => self::canonicalVariables(),
                'ai_connection_id' => $this->defaultAiConnectionId(),
                'tools' => 'default',
                'is_active' => true,
                'user_id' => $this->systemUserId(),
                'routing_policy' => 'quick_free',
                'settings' => [
                    'is_system_default' => true,
                    'ownership' => 'settings_binding',
                ],
            ]);
            $existing->save();
            $created = true;
        } elseif ((string) ($existing->hook_version ?? '') !== self::HOOK_VERSION) {
            // Advance bound system default to append-batch contract when still on prior hook version.
            $settings = is_array($existing->settings) ? $existing->settings : [];
            if (($settings['is_system_default'] ?? false) === true) {
                $existing->fill([
                    'markdown_content' => self::canonicalDefaultMarkdown(),
                    'description' => 'Append exactly 10 new Site CTA templates from MCP Site Context.',
                    'hook_version' => self::HOOK_VERSION,
                    'variables' => self::canonicalVariables(),
                ])->save();
            }
        }

        $promptId = (int) $existing->id;
        $bindings = $this->settings->getPromptHookBindings();
        $bindingSet = false;
        if (! isset($bindings[self::HOOK_KEY]) || (int) $bindings[self::HOOK_KEY] !== $promptId) {
            if (! isset($bindings[self::HOOK_KEY])) {
                $this->settings->savePromptHookBindings([
                    self::HOOK_KEY => $promptId,
                ]);
                $bindingSet = true;
            }
        }

        return [
            'prompt_id' => $promptId,
            'created' => $created,
            'binding_set' => $bindingSet,
        ];
    }

    public static function canonicalDefaultMarkdown(): string
    {
        $value = trim((string) (self::spec()['canonical_default']['markdown'] ?? ''));
        if ($value === '') {
            throw new RuntimeException('Canonical Site CTA templates prompt is empty.');
        }

        return $value;
    }

    /**
     * @return list<array{name: string, description: string}>
     */
    public static function canonicalVariables(): array
    {
        $rows = [];
        foreach ((array) (self::spec()['input_schema'] ?? []) as $name => $field) {
            $rows[] = [
                'name' => (string) $name,
                'description' => (string) (((array) $field)['label'] ?? $name),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function spec(): array
    {
        $path = PromptHookDefinitionLoader::defaultV01Directory()
            .DIRECTORY_SEPARATOR.self::HOOK_KEY.'@'.self::HOOK_VERSION.'.json';
        $value = json_decode((string) file_get_contents($path), true);
        if (! is_array($value)) {
            throw new RuntimeException('Canonical Site CTA templates Hook JSON is invalid.');
        }

        return $value;
    }

    private function defaultAiConnectionId(): ?int
    {
        try {
            $id = ApiConnection::query()->orderBy('id')->value('id');

            return $id !== null ? (int) $id : null;
        } catch (\Throwable) {
            return null;
        }
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
        }

        return 1;
    }
}
