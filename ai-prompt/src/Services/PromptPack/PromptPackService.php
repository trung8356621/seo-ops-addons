<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptPack;

use Omnichannel\Addons\AiPrompt\Exceptions\ConfigurationPackageException;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookStatus;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Omnichannel\Addons\AiPrompt\Services\ConfigurationPackages\ConfigurationImportPlan;
use Omnichannel\Addons\AiPrompt\Services\ConfigurationPackages\ConfigurationJsonGuard;
use Omnichannel\Addons\AiPrompt\Services\ConfigurationPackages\ConfigurationPackageLimits;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\ConfigurationPackageType;
use Omnichannel\Addons\Media\Support\ImageToolType;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

final class PromptPackService
{
    public const MAX_PROMPTS = 200;

    public const MAX_NAME = 180;

    public const MAX_DESCRIPTION = 2000;

    public const MAX_CONTENT = 100000;

    public function __construct(
        private readonly ConfigurationJsonGuard $guard = new ConfigurationJsonGuard(),
        private readonly PromptPortableIdentity $identity = new PromptPortableIdentity(),
        /** @var list<string> */
        private readonly array $knownHookKeys = [],
    ) {}

    /**
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    public function export(int $userId, array $ids = [], bool $includeInactive = true): array
    {
        $query = SeoPrompt::query()->where('user_id', $userId);
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }
        if (! $includeInactive) {
            $query->where('is_active', true);
        }

        $prompts = [];
        foreach ($query->orderBy('id')->get() as $prompt) {
            $prompts[] = $this->serialize($prompt);
        }

        return [
            'package_type' => ConfigurationPackageType::PromptPack->value,
            'schema_version' => '1.0',
            'meta' => [
                'app' => 'seo-ops',
                'exported_at' => gmdate('c'),
            ],
            'prompts' => $prompts,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function plan(array $data, int $userId, string $bulkPolicy = 'update'): ConfigurationImportPlan
    {
        $items = is_array($data['prompts'] ?? null) ? $data['prompts'] : [];
        if (count($items) > self::MAX_PROMPTS) {
            throw ConfigurationPackageException::rejected('too many prompts.');
        }

        $planned = [];
        $warnings = [];
        foreach ($items as $index => $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $planned[] = $this->planOne($raw, $userId, $index, $bulkPolicy, $warnings);
        }

        return new ConfigurationImportPlan(
            type: ConfigurationPackageType::PromptPack,
            schemaVersion: (string) ($data['schema_version'] ?? '1.0'),
            mode: $bulkPolicy,
            sections: [],
            prompts: $planned,
            warnings: $warnings,
            payload: $data,
        );
    }

    public function parseAndPlan(string $rawJson, int $userId, string $bulkPolicy = 'update'): ConfigurationImportPlan
    {
        $data = $this->guard->decode($rawJson, ConfigurationPackageLimits::promptPack());
        $type = trim((string) ($data['package_type'] ?? ''));
        if ($type !== ConfigurationPackageType::PromptPack->value && $type !== ConfigurationPackageType::SeoConfigurationBundle->value) {
            throw ConfigurationPackageException::rejected('unexpected package_type for Prompt Pack.');
        }
        if (trim((string) ($data['schema_version'] ?? '')) !== '1.0') {
            throw ConfigurationPackageException::unsupportedVersion((string) ($data['schema_version'] ?? ''), '1.0');
        }
        $pack = $type === ConfigurationPackageType::SeoConfigurationBundle->value
            ? (is_array($data['prompts'] ?? null) ? ['schema_version' => '1.0', 'prompts' => $data['prompts']['prompts'] ?? $data['prompts']] : $data)
            : $data;

        return $this->plan($pack, $userId, $bulkPolicy);
    }

    /**
     * @param  array<int, string>  $overrides  index => update|copy|skip
     */
    public function apply(ConfigurationImportPlan $plan, int $userId, array $overrides = []): int
    {
        if (! SeoAccessControl::canAccessManagerFeatures() && ! SeoAccessControl::canAccessPlannerFeatures()) {
            throw ConfigurationPackageException::rejected('Not authorized to import prompts.');
        }

        $applied = 0;
        foreach ($plan->prompts as $index => $row) {
            $action = $overrides[$index] ?? (string) ($row['action'] ?? 'skip');
            if ($action === 'skip' || ($row['blocked'] ?? false) === true) {
                continue;
            }
            $record = is_array($row['normalized'] ?? null) ? $row['normalized'] : [];
            if ($action === 'update' && isset($row['existing_id'])) {
                $prompt = SeoPrompt::query()->withTrashed()->where('user_id', $userId)->whereKey((int) $row['existing_id'])->first();
                if ($prompt instanceof SeoPrompt) {
                    if (method_exists($prompt, 'trashed') && $prompt->trashed()) {
                        $prompt->restore();
                    }
                    $this->fill($prompt, $record, $userId);
                    $prompt->save();
                    $applied++;
                    continue;
                }
            }
            $prompt = new SeoPrompt();
            $this->fill($prompt, $record, $userId);
            if ($action === 'copy') {
                $prompt->name = $this->uniqueCopyName($userId, (string) $prompt->name);
            }
            $prompt->save();
            $applied++;
        }

        return $applied;
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(SeoPrompt $prompt): array
    {
        $settings = is_array($prompt->settings) ? $prompt->settings : [];
        unset($settings['detected_tags']);
        $family = trim((string) ($settings['routing_family_key'] ?? ''));

        return [
            'portable_uuid' => $this->identity->ensure($prompt),
            'name' => (string) ($prompt->name ?? $prompt->title ?? ''),
            'description' => (string) ($prompt->description ?? ''),
            'content' => (string) ($prompt->markdown_content ?? ''),
            'hook' => (string) ($prompt->hook_key ?? ''),
            'hook_version' => (string) ($prompt->hook_version ?? ''),
            'tool' => ImageToolType::fromMixed($prompt->tools ?? 'default')->value,
            'enabled' => (bool) $prompt->is_active,
            'execution' => [
                'mode' => in_array((string) ($prompt->routing_mode ?? 'auto'), ['auto', 'override'], true)
                    ? (string) $prompt->routing_mode
                    : 'auto',
                'profile' => (string) ($prompt->routing_profile_key ?? ''),
                'family_key' => $family,
            ],
            'variables' => is_array($prompt->variables) ? $prompt->variables : [],
            'hook_settings' => is_array($prompt->hook_settings) ? $prompt->hook_settings : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function planOne(array $raw, int $userId, int $index, string $bulkPolicy, array &$warnings): array
    {
        $uuid = trim((string) ($raw['portable_uuid'] ?? ''));
        $name = (string) ($raw['name'] ?? '');
        $content = (string) ($raw['content'] ?? $raw['markdown_content'] ?? '');
        $hook = trim((string) ($raw['hook'] ?? $raw['hook_key'] ?? ''));
        $rawTool = strtolower(trim((string) ($raw['tool'] ?? $raw['tools'] ?? 'default')));
        if ($rawTool === 'text') {
            $rawTool = ImageToolType::Default->value;
        }
        $allowedTools = array_map(static fn (ImageToolType $case): string => $case->value, ImageToolType::cases());
        if (! in_array($rawTool, $allowedTools, true)) {
            $warnings[] = 'Unknown tool on "'.$name.'"; imported disabled.';
            $rawTool = ImageToolType::Default->value;
            $raw['enabled'] = false;
        }
        $tool = ImageToolType::fromMixed($rawTool)->value;
        $execution = is_array($raw['execution'] ?? null) ? $raw['execution'] : [];

        if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
            $warnings[] = 'Prompt #'.($index + 1).' has an invalid name.';

            return ['index' => $index, 'action' => 'skip', 'blocked' => true, 'reason' => 'invalid_name'];
        }
        if (mb_strlen($content) > self::MAX_CONTENT) {
            $warnings[] = 'Prompt "'.$name.'" exceeds maximum content length.';

            return ['index' => $index, 'action' => 'skip', 'blocked' => true, 'reason' => 'too_long'];
        }

        $enabled = (bool) ($raw['enabled'] ?? true);
        $hookOk = $hook === '' || $this->hookExists($hook);
        if ($hook !== '' && ! $hookOk) {
            $warnings[] = 'Unknown hook: '.$hook;
            $enabled = false;
        } elseif ($hook !== '' && $this->hookDisabled($hook)) {
            $warnings[] = 'Disabled hook: '.$hook;
            $enabled = false;
        }

        $profile = trim((string) ($execution['profile'] ?? ''));
        if ($profile !== '' && AiExecutionProfile::tryFrom($profile) === null) {
            $warnings[] = 'Unknown routing profile on "'.$name.'"; using automatic.';
            $profile = '';
        }

        $routingMode = in_array((string) ($execution['mode'] ?? 'auto'), ['auto', 'override'], true)
            ? (string) ($execution['mode'] ?? 'auto')
            : 'auto';

        $normalized = [
            'portable_uuid' => $this->identity->isUuid($uuid) ? $uuid : '',
            'name' => $name,
            'description' => mb_substr((string) ($raw['description'] ?? ''), 0, self::MAX_DESCRIPTION),
            'content' => $content,
            'hook' => $hookOk ? $hook : '',
            'hook_version' => (string) ($raw['hook_version'] ?? ''),
            'tool' => $tool,
            'enabled' => $enabled,
            'routing_mode' => $routingMode,
            'routing_profile_key' => $profile,
            'family_key' => (string) ($execution['family_key'] ?? ''),
            'variables' => is_array($raw['variables'] ?? null) ? $raw['variables'] : [],
            'hook_settings' => is_array($raw['hook_settings'] ?? null) ? $raw['hook_settings'] : [],
        ];

        $existing = null;
        if ($normalized['portable_uuid'] !== '') {
            $existing = $this->findByUuid($userId, $normalized['portable_uuid']);
        }

        $action = 'create';
        $conflict = null;
        if ($existing instanceof SeoPrompt) {
            $action = in_array($bulkPolicy, ['update', 'copy', 'skip'], true) ? $bulkPolicy : 'update';
            $conflict = 'portable_uuid';
        } else {
            $byName = SeoPrompt::query()->where('user_id', $userId)->where('name', $name)->first();
            if ($byName instanceof SeoPrompt) {
                $conflict = 'name';
                $action = $bulkPolicy === 'update' ? 'copy' : (in_array($bulkPolicy, ['copy', 'skip'], true) ? $bulkPolicy : 'copy');
                $warnings[] = 'Name conflict for "'.$name.'" (different portable id).';
            }
        }

        return [
            'index' => $index,
            'name' => $name,
            'hook' => $hook,
            'tool' => $tool,
            'action' => $action,
            'conflict' => $conflict,
            'blocked' => false,
            'existing_id' => $existing instanceof SeoPrompt ? (int) $existing->id : null,
            'normalized' => $normalized,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function fill(SeoPrompt $prompt, array $record, int $userId): void
    {
        $prompt->user_id = $userId;
        $prompt->name = (string) $record['name'];
        $prompt->title = (string) $record['name'];
        $prompt->description = (string) $record['description'];
        $prompt->markdown_content = (string) $record['content'];
        $prompt->hook_key = (string) $record['hook'] !== '' ? (string) $record['hook'] : null;
        $prompt->hook_version = (string) $record['hook_version'] !== '' ? (string) $record['hook_version'] : null;
        $prompt->tools = (string) $record['tool'];
        $prompt->is_active = (bool) $record['enabled'];
        $prompt->routing_mode = (string) $record['routing_mode'];
        $prompt->routing_profile_key = (string) $record['routing_profile_key'] !== '' ? (string) $record['routing_profile_key'] : null;
        $prompt->ai_connection_id = null;
        $prompt->variables = $record['variables'];
        $prompt->hook_settings = $record['hook_settings'];
        $settings = is_array($prompt->settings) ? $prompt->settings : [];
        unset($settings['detected_tags']);
        $uuid = (string) $record['portable_uuid'];
        if ($uuid === '') {
            $uuid = (string) \Illuminate\Support\Str::uuid();
        }
        $settings['portable_uuid'] = $uuid;
        $family = trim((string) $record['family_key']);
        if ($family !== '') {
            $settings['routing_family_key'] = $family;
        }
        $prompt->settings = $settings;
        if ($this->identity->isUuid($uuid)) {
            try {
                $prompt->setAttribute('portable_uuid', $uuid);
            } catch (\Throwable) {
            }
        }
    }

    private function findByUuid(int $userId, string $uuid): ?SeoPrompt
    {
        $query = SeoPrompt::query()->withTrashed()->where('user_id', $userId);
        try {
            $byColumn = (clone $query)->where('portable_uuid', $uuid)->first();
            if ($byColumn instanceof SeoPrompt) {
                return $byColumn;
            }
        } catch (\Throwable) {
        }

        foreach ($query->get() as $prompt) {
            $settings = is_array($prompt->settings) ? $prompt->settings : [];
            if (trim((string) ($settings['portable_uuid'] ?? '')) === $uuid) {
                return $prompt;
            }
        }

        return null;
    }

    private function uniqueCopyName(int $userId, string $name): string
    {
        $base = mb_substr($name.' (copy)', 0, self::MAX_NAME);
        $candidate = $base;
        $i = 2;
        while (SeoPrompt::query()->where('user_id', $userId)->where('name', $candidate)->exists()) {
            $candidate = mb_substr($base.' '.$i, 0, self::MAX_NAME);
            $i++;
        }

        return $candidate;
    }

    private function hookExists(string $hook): bool
    {
        if (in_array($hook, $this->knownHookKeys, true)) {
            return true;
        }
        try {
            if (! function_exists('app')) {
                return false;
            }
            $registry = app(PromptHookRuntimeRegistry::class);
            foreach ($registry->list() as $definition) {
                if ($definition->key->value === $hook) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    private function hookDisabled(string $hook): bool
    {
        try {
            if (! function_exists('app')) {
                return false;
            }
            $registry = app(PromptHookRuntimeRegistry::class);
            foreach ($registry->list() as $definition) {
                if ($definition->key->value === $hook && $definition->status === PromptHookStatus::Disabled) {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        return false;
    }
}
