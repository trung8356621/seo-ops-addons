<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Entities;

use Omnichannel\Addons\AiPrompt\PromptHooks\Contracts\PromptHookEntityResolverContract;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookException;
use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookErrorCode;
use InvalidArgumentException;

/**
 * Chỉ map entity key → class đã đăng ký. Manifest không được chỉ định class PHP tùy ý.
 */
final class PromptHookEntityResolverRegistry
{
    /** @var array<string, class-string<PromptHookEntityResolverContract>> */
    private array $map = [
        'article' => ArticlePromptHookEntityResolver::class,
    ];

    public function register(string $key, string $resolverClass): void
    {
        if (! is_subclass_of($resolverClass, PromptHookEntityResolverContract::class)) {
            throw new InvalidArgumentException(
                "Resolver [{$resolverClass}] must implement PromptHookEntityResolverContract.",
            );
        }

        $this->map[$key] = $resolverClass;
    }

    public function has(string $key): bool
    {
        return isset($this->map[$key]);
    }

    public function get(string $key): PromptHookEntityResolverContract
    {
        if (! isset($this->map[$key])) {
            throw new PromptHookException(
                PromptHookErrorCode::HookInputInvalid,
                "Unknown hook entity [{$key}].",
            );
        }

        /** @var PromptHookEntityResolverContract $resolver */
        $resolver = app($this->map[$key]);

        return $resolver;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->map);
    }
}
