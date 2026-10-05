<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use RuntimeException;

final class DefaultAgentRuntimePromptInstaller
{
    /** @var array<string, array{hook: string, name: string, version: string}> */
    private const TYPES = [
        'routing' => ['hook' => 'agent.routing.decide', 'name' => 'Agent Routing / JEV', 'version' => '0.2.0'],
        'response' => ['hook' => 'agent.response.compose', 'name' => 'Agent Response Composer', 'version' => '0.2.0'],
    ];

    public function __construct(private readonly SeoCreateArticleSettingsService $settings) {}

    /** @return array<string, array{prompt_id: int, created: bool, binding_set: bool, restored: bool, ownership_repaired: bool}> */
    public function install(bool $restoreCanonical = false): array
    {
        $results = [];
        foreach (array_keys(self::TYPES) as $type) {
            $results[$type] = $this->installType($type, $restoreCanonical);
        }

        return $results;
    }

    /** @return array{prompt_id: int, created: bool, binding_set: bool, restored: bool, ownership_repaired: bool} */
    public function installType(string $type, bool $restoreCanonical = false): array
    {
        $config = self::TYPES[$type] ?? throw new RuntimeException("Unknown Agent Runtime prompt type [{$type}].");
        $ownerUserId = $this->managedPromptOwnerUserId();
        $prompt = SeoPrompt::query()
            ->where('hook_key', $config['hook'])
            ->where('name', $config['name'])
            ->orderBy('id')
            ->first();
        $created = false;
        $restored = false;
        $ownershipRepaired = false;

        if ($prompt === null) {
            $prompt = new SeoPrompt;
            $prompt->fill([
                'name' => $config['name'],
                'title' => $config['name'],
                'markdown_content' => self::canonicalDefaultMarkdown($type),
                'description' => self::canonicalDescription($type),
                'hook_key' => $config['hook'],
                'hook_version' => $config['version'],
                'variables' => [],
                'tools' => 'default',
                'is_active' => true,
                'user_id' => $ownerUserId,
                'settings' => ['is_system_default' => true, 'ownership' => 'settings_binding'],
            ]);
            $prompt->save();
            $created = true;
        } else {
            if ((int) $prompt->user_id !== $ownerUserId && $ownerUserId > 0) {
                $prompt->user_id = $ownerUserId;
                $ownershipRepaired = true;
            }

            $settings = is_array($prompt->settings) ? $prompt->settings : [];
            $settings['is_system_default'] = true;
            $settings['ownership'] = 'settings_binding';
            $prompt->settings = $settings;

            if ($restoreCanonical || trim((string) $prompt->hook_version) !== $config['version']) {
                $prompt->markdown_content = self::canonicalDefaultMarkdown($type);
                $prompt->description = self::canonicalDescription($type);
                $prompt->hook_version = $config['version'];
                $prompt->variables = [];
                $prompt->is_active = true;
                $restored = true;
            }
            if ($prompt->isDirty()) {
                $prompt->save();
            }
        }

        $bindings = $this->settings->getPromptHookBindings();
        $bindingSet = false;
        if (! isset($bindings[$config['hook']])) {
            $this->settings->savePromptHookBindings([$config['hook'] => (int) $prompt->id]);
            $bindingSet = true;
        }

        return [
            'prompt_id' => (int) $prompt->id,
            'created' => $created,
            'binding_set' => $bindingSet,
            'restored' => $restored,
            'ownership_repaired' => $ownershipRepaired,
        ];
    }

    public static function canonicalDefaultMarkdown(string $type): string
    {
        $spec = self::loadCanonicalSpec($type);
        $default = is_array($spec['canonical_default'] ?? null) ? $spec['canonical_default'] : [];
        $markdown = trim((string) ($default['markdown'] ?? ''));
        if ($markdown === '') {
            throw new RuntimeException("Canonical Agent Runtime markdown is empty for [{$type}].");
        }

        return $markdown;
    }

    public function reconcileContractVersion(string $type, SeoPrompt $prompt): bool
    {
        $config = self::TYPES[$type] ?? throw new RuntimeException("Unknown Agent Runtime prompt type [{$type}].");
        $settings = is_array($prompt->settings) ? $prompt->settings : [];
        if ((string) $prompt->hook_key !== $config['hook']
            || (string) $prompt->name !== $config['name']
            || ($settings['is_system_default'] ?? false) !== true
            || ($settings['ownership'] ?? null) !== 'settings_binding'
            || trim((string) $prompt->hook_version) === $config['version']
        ) {
            return false;
        }

        $this->installType($type);

        return true;
    }

    /** @return array<string, mixed> */
    private static function loadCanonicalSpec(string $type): array
    {
        $config = self::TYPES[$type] ?? throw new RuntimeException("Unknown Agent Runtime prompt type [{$type}].");
        $path = PromptHookDefinitionLoader::defaultV01Directory().DIRECTORY_SEPARATOR.$config['hook'].'@'.$config['version'].'.json';
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Canonical Agent Runtime Hook JSON is invalid for [{$type}].");
        }

        return $decoded;
    }

    private static function canonicalDescription(string $type): string
    {
        return trim((string) (self::loadCanonicalSpec($type)['description'] ?? ''));
    }

    private function managedPromptOwnerUserId(): int
    {
        try {
            $owner = (int) SeoAccessControl::accountSiteOwnerId();
            if ($owner > 0) {
                return $owner;
            }
        } catch (\Throwable) {
        }

        try {
            $owner = SeoAccessControl::panelOwnerId();
            if ($owner !== null && $owner > 0) {
                return $owner;
            }
        } catch (\Throwable) {
        }

        try {
            $owner = SeoPrompt::query()->whereNotNull('user_id')->where('user_id', '>', 0)
                ->groupBy('user_id')->orderByRaw('COUNT(*) DESC')->orderBy('user_id')->value('user_id');
            if ($owner !== null && (int) $owner > 0) {
                return (int) $owner;
            }
        } catch (\Throwable) {
        }

        try {
            return (int) (\App\Models\User::query()->orderBy('id')->value('id') ?: 1);
        } catch (\Throwable) {
            return 1;
        }
    }
}
