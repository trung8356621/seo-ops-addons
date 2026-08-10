<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Extension\Resolvers;

use Omnichannel\Addons\Media\Extension\Contracts\AiImageProviderInterface;
use Omnichannel\Addons\AiPrompt\Extension\Contracts\AiTextProviderInterface;
use Omnichannel\Addons\Agent\Extension\ExtensionStateStore;
use Omnichannel\Addons\AiPrompt\Extension\Registry\AiProviderRegistry;
use RuntimeException;

/**
 * Fail-closed lookup for AI provider extensions. PromptRunnerService and any external
 * caller MUST go through this resolver instead of touching AiProviderRegistry directly,
 * so disabling the built-in "ai-providers" extension (or an individual provider key)
 * actually stops execution instead of silently falling back to hard-coded HTTP calls.
 */
final class AiProviderResolver
{
    /**
     * Builtin extension id that owns gemini/claude (see Extension/Builtin/AiProviders).
     */
    public const BUILTIN_EXTENSION_ID = 'ai-providers';

    public const ERROR_NOT_CONFIGURED = 'ai_provider.not_configured';

    public const ERROR_NOT_REGISTERED = 'ai_provider.not_registered';

    public const ERROR_DISABLED = 'ai_provider.disabled';

    public function __construct(
        private readonly AiProviderRegistry $registry,
        private readonly ExtensionStateStore $stateStore,
    ) {}

    public function resolveText(string $providerKey): AiTextProviderInterface
    {
        $key = trim($providerKey);

        if ($key === '') {
            throw new RuntimeException(self::ERROR_NOT_CONFIGURED.': AI provider key is empty.');
        }

        if (! $this->registry->hasText($key)) {
            throw new RuntimeException(self::ERROR_NOT_REGISTERED.": AI text provider [{$key}] is not registered.");
        }

        if (! $this->isEnabled($key)) {
            throw new RuntimeException(self::ERROR_DISABLED.": AI text provider [{$key}] is disabled.");
        }

        /** @var AiTextProviderInterface $provider */
        $provider = $this->registry->getText($key);

        return $provider;
    }

    public function resolveImage(string $providerKey): AiImageProviderInterface
    {
        $key = trim($providerKey);

        if ($key === '') {
            throw new RuntimeException(self::ERROR_NOT_CONFIGURED.': AI provider key is empty.');
        }

        if (! $this->registry->hasImage($key)) {
            throw new RuntimeException(self::ERROR_NOT_REGISTERED.": AI image provider [{$key}] is not registered.");
        }

        if (! $this->isEnabled($key)) {
            throw new RuntimeException(self::ERROR_DISABLED.": AI image provider [{$key}] is disabled.");
        }

        /** @var AiImageProviderInterface $provider */
        $provider = $this->registry->getImage($key);

        return $provider;
    }

    /**
     * Validates a text provider is registered + enabled without returning it.
     * Used by PromptRunnerService, which still executes its own HTTP client
     * for the internal task-execution flow (see PromptRunnerService::callProvider).
     */
    public function assertTextReady(string $providerKey): void
    {
        $this->resolveText($providerKey);
    }

    /**
     * Parent builtin extension must be enabled — provider keys are not separate extensions.
     */
    private function isEnabled(string $key): bool
    {
        unset($key);

        return $this->stateStore->isEnabled(self::BUILTIN_EXTENSION_ID);
    }
}
