<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks;

use Omnichannel\Addons\AiPrompt\PromptHooks\Data\PromptHookDefinition;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookException;
use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookErrorCode;
use Illuminate\Support\Facades\Cache;

/**
 * In-memory registry (singleton). Cache::forget marker giúp clear xuyên request khi cần.
 */
final class PromptHookRegistry
{
    public const CACHE_KEY = 'seo_content_ai.prompt_hooks.registry.v1';

    /** @var array<string, PromptHookDefinition>|null */
    private ?array $definitions = null;

    public function __construct(
        private readonly PromptHookManifestLoader $loader,
    ) {}

    /**
     * @return array<string, PromptHookDefinition>
     */
    public function all(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        try {
            if (Cache::get(self::CACHE_KEY) === 'cleared') {
                Cache::forget(self::CACHE_KEY);
            }
        } catch (\Throwable) {
            // Cache may be unavailable in pure unit tests.
        }

        $this->definitions = $this->index($this->loader->loadAll());

        try {
            Cache::forever(self::CACHE_KEY, 'loaded:'.count($this->definitions));
        } catch (\Throwable) {
            // Ignore cache write failures.
        }

        return $this->definitions;
    }

    public function get(string $key): PromptHookDefinition
    {
        $all = $this->all();
        if (! isset($all[$key])) {
            throw new PromptHookException(
                PromptHookErrorCode::HookNotFound,
                "Prompt hook [{$key}] not found.",
            );
        }

        return $all[$key];
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    /**
     * @return array<string, string> key => label
     */
    public function optionsForSelect(): array
    {
        $options = ['' => (string) __('seo-content-ai::prompt_hooks.none')];

        foreach ($this->all() as $definition) {
            $options[$definition->key] = $definition->label();
        }

        return $options;
    }

    public function clearCache(): void
    {
        $this->definitions = null;

        try {
            Cache::forever(self::CACHE_KEY, 'cleared');
        } catch (\Throwable) {
            try {
                Cache::forget(self::CACHE_KEY);
            } catch (\Throwable) {
                // Ignore.
            }
        }
    }

    /**
     * @param  list<PromptHookDefinition>  $definitions
     * @return array<string, PromptHookDefinition>
     */
    private function index(array $definitions): array
    {
        $indexed = [];
        foreach ($definitions as $definition) {
            if (isset($indexed[$definition->key])) {
                throw new PromptHookException(
                    PromptHookErrorCode::HookDuplicateKey,
                    "Duplicate prompt hook key [{$definition->key}] — fail closed (no silent overwrite).",
                );
            }
            $indexed[$definition->key] = $definition;
        }

        return $indexed;
    }
}
