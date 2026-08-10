<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Contracts;

use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookFailure;
use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookFailureCode;

/**
 * Filesystem catalog for reusable output contracts (single source of truth).
 *
 * Path: resources/prompt-hooks/contracts/{key}@{version}.md
 */
final class PromptOutputContractCatalog
{
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    /** @var array<string, PromptOutputContract>|null keyed by contract key (latest pinned) */
    private ?array $byKey = null;

    /** @var array<string, PromptOutputContract>|null keyed by key@version */
    private ?array $byId = null;

    public function __construct(
        private readonly string $directory,
    ) {}

    public static function defaultDirectory(): string
    {
        // Contracts → PromptHooks → src → ai-prompt
        return dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'prompt-hooks'.DIRECTORY_SEPARATOR.'contracts';
    }

    public function clearCache(): void
    {
        $this->byKey = null;
        $this->byId = null;
    }

    public function get(string $key, ?string $version = null): PromptOutputContract
    {
        $this->ensureLoaded();
        $key = trim($key);

        if ($version !== null && trim($version) !== '') {
            $id = $key.'@'.trim($version);
            if (! isset($this->byId[$id])) {
                throw new PromptHookFailure(
                    PromptHookFailureCode::DefinitionInvalid,
                    "Output contract [{$id}] not found.",
                );
            }

            return $this->byId[$id];
        }

        if (! isset($this->byKey[$key])) {
            throw new PromptHookFailure(
                PromptHookFailureCode::DefinitionInvalid,
                "Output contract [{$key}] not found.",
            );
        }

        return $this->byKey[$key];
    }

    public function has(string $key): bool
    {
        $this->ensureLoaded();

        return isset($this->byKey[trim($key)]);
    }

    /**
     * @return list<PromptOutputContract>
     */
    public function all(): array
    {
        $this->ensureLoaded();

        return array_values($this->byId ?? []);
    }

    private function ensureLoaded(): void
    {
        if ($this->byId !== null) {
            return;
        }

        $byId = [];
        $byKey = [];

        if (! is_dir($this->directory)) {
            $this->byId = [];
            $this->byKey = [];

            return;
        }

        $files = glob($this->directory.DIRECTORY_SEPARATOR.'*.md') ?: [];
        foreach ($files as $path) {
            $base = basename($path, '.md');
            if (! str_contains($base, '@')) {
                throw new PromptHookFailure(
                    PromptHookFailureCode::DefinitionInvalid,
                    "Output contract filename must be key@version.md: {$path}",
                );
            }

            [$key, $version] = explode('@', $base, 2);
            $key = trim($key);
            $version = trim($version);
            if ($key === '' || $version === '' || preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw new PromptHookFailure(
                    PromptHookFailureCode::DefinitionInvalid,
                    "Invalid output contract key/version in {$path}",
                );
            }

            $body = trim((string) file_get_contents($path));
            if ($body === '') {
                throw new PromptHookFailure(
                    PromptHookFailureCode::DefinitionInvalid,
                    "Output contract body empty: {$path}",
                );
            }

            $contract = new PromptOutputContract($key, $version, $body, $path);
            $id = $contract->cacheKey();
            if (isset($byId[$id])) {
                throw new PromptHookFailure(
                    PromptHookFailureCode::DefinitionInvalid,
                    "Duplicate output contract [{$id}]",
                );
            }
            $byId[$id] = $contract;

            if (! isset($byKey[$key]) || version_compare($version, $byKey[$key]->version, '>')) {
                $byKey[$key] = $contract;
            }
        }

        $this->byId = $byId;
        $this->byKey = $byKey;
    }
}
