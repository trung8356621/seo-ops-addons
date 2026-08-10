<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Extension\Registry;

use Omnichannel\Addons\AiPrompt\Extension\Contracts\PromptHookContributor;
use InvalidArgumentException;

final class PromptHookExtensionRegistry
{
    /** @var array<string, PromptHookContributor> */
    private array $contributors = [];

    public function register(string $id, PromptHookContributor $contributor): void
    {
        if (isset($this->contributors[$id])) {
            throw new InvalidArgumentException("Prompt hook contributor [{$id}] already registered.");
        }

        $this->contributors[$id] = $contributor;
    }

    public function get(string $id): ?PromptHookContributor
    {
        return $this->contributors[$id] ?? null;
    }

    /**
     * @return array<string, PromptHookContributor>
     */
    public function all(): array
    {
        return $this->contributors;
    }

    public function has(string $id): bool
    {
        return isset($this->contributors[$id]);
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->contributors);
    }
}
