<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Extension\Resolvers;

use Omnichannel\Addons\Agent\Extension\Contracts\PipelineDefinitionInterface;
use Omnichannel\Addons\Agent\Extension\ExtensionStateStore;
use Omnichannel\Addons\Agent\Extension\Registry\PipelineRegistry;
use RuntimeException;

/**
 * Fail-closed lookup for content pipeline definitions.
 */
final class PipelineResolver
{
    /**
     * Builtin extension id that owns article/rewrite/improve/translate/product
     * (see Extension/Builtin/ContentPipelines).
     */
    public const BUILTIN_EXTENSION_ID = 'content-pipelines';

    public const ERROR_NOT_CONFIGURED = 'pipeline.not_configured';

    public const ERROR_NOT_REGISTERED = 'pipeline.not_registered';

    public const ERROR_DISABLED = 'pipeline.disabled';

    public function __construct(
        private readonly PipelineRegistry $registry,
        private readonly ExtensionStateStore $stateStore,
    ) {}

    public function resolve(string $key): PipelineDefinitionInterface
    {
        $key = trim($key);

        if ($key === '') {
            throw new RuntimeException(self::ERROR_NOT_CONFIGURED.': Pipeline key is empty.');
        }

        if (! $this->registry->hasDefinition($key)) {
            throw new RuntimeException(self::ERROR_NOT_REGISTERED.": Pipeline [{$key}] is not registered.");
        }

        if (! $this->isEnabled($key)) {
            throw new RuntimeException(self::ERROR_DISABLED.": Pipeline [{$key}] is disabled.");
        }

        /** @var PipelineDefinitionInterface $definition */
        $definition = $this->registry->getDefinition($key);

        return $definition;
    }

    private function isEnabled(string $key): bool
    {
        unset($key);

        return $this->stateStore->isEnabled(self::BUILTIN_EXTENSION_ID);
    }
}
