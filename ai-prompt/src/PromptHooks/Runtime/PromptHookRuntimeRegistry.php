<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookDefinition;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookStatus;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookVersion;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\DefinitionNotFound;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\ExperimentalNotAllowed;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\HookDisabled;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\VersionNotFound;
use Illuminate\Support\Facades\Log;

final class PromptHookRuntimeRegistry
{
    public function __construct(
        private readonly PromptHookDefinitionLoader $loader,
    ) {}

    public function get(string $key, string $version): PromptHookDefinition
    {
        $id = $key.'@'.PromptHookVersion::parse($version)->toString();
        $all = $this->loader->indexed();
        if (! isset($all[$id])) {
            // Try find key exists with other versions
            foreach ($all as $def) {
                if ($def->key->value === $key) {
                    throw new VersionNotFound($key, $version);
                }
            }
            throw new DefinitionNotFound($key, $version);
        }

        return $all[$id];
    }

    public function has(string $key, string $version): bool
    {
        $id = $key.'@'.PromptHookVersion::parse($version)->toString();

        return isset($this->loader->indexed()[$id]);
    }

    /**
     * @return list<PromptHookDefinition>
     */
    public function list(): array
    {
        return $this->loader->loadAll();
    }

    /**
     * @return list<PromptHookDefinition>
     */
    public function listByStatus(PromptHookStatus $status): array
    {
        return array_values(array_filter(
            $this->list(),
            static fn (PromptHookDefinition $d): bool => $d->status === $status,
        ));
    }

    /**
     * Assert definition may execute under policy. Does not fallback version.
     *
     * @param  list<string>  $experimentalAllowlist  hook keys
     */
    public function assertExecutable(
        PromptHookDefinition $definition,
        bool $experimentalAllowed,
        array $experimentalAllowlist = [],
    ): void {
        if ($definition->status === PromptHookStatus::Disabled) {
            throw new HookDisabled($definition->key->value, $definition->version->toString());
        }

        if ($definition->status === PromptHookStatus::Deprecated) {
            Log::warning('prompt_hook.deprecated_executed', [
                'hook_key' => $definition->key->value,
                'hook_version' => $definition->version->toString(),
            ]);
        }

        if ($definition->status === PromptHookStatus::Experimental) {
            $allowed = $experimentalAllowed
                || in_array($definition->key->value, $experimentalAllowlist, true);
            if (! $allowed) {
                throw new ExperimentalNotAllowed(
                    $definition->key->value,
                    $definition->version->toString(),
                );
            }
        }
    }

    public function clearCache(): void
    {
        $this->loader->clearCache();
    }
}
