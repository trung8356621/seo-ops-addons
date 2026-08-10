<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookFailure;
use Illuminate\Support\Str;

/**
 * Per-hook bridge: legacy | shadow | hook. Default legacy.
 */
final class PromptHookCallerBridge
{
    public function __construct(
        private readonly PromptHookMigrationFlags $flags,
        private readonly PromptHookRuntimeEngine $engine,
        private readonly PromptHookLiveShadowGate $liveShadowGate = new PromptHookLiveShadowGate(new PromptHookMigrationFlags),
    ) {}

    /**
     * @param  callable(): mixed  $legacyExecute
     * @param  callable(PromptHookRuntimeResult): mixed|null  $mapHookResult
     * @param  callable(mixed): array{type: string, raw: string, value: mixed, warnings?: list<string>}|null  $mapLegacyOutput
     */
    public function run(
        string $hookKey,
        string $version,
        PromptHookExecutionInput $envelope,
        callable $legacyExecute,
        ?callable $mapHookResult = null,
        ?callable $mapLegacyOutput = null,
        ?string $correlationId = null,
    ): mixed {
        $mode = $this->flags->mode($hookKey);
        $correlationId ??= Str::uuid()->toString();

        if ($mode === PromptHookRuntimeMode::Legacy) {
            return $legacyExecute();
        }

        if ($mode === PromptHookRuntimeMode::Shadow) {
            if ($this->liveShadowGate->allows($hookKey)) {
                $legacyResult = $legacyExecute();
                try {
                    $this->engine->execute($hookKey, $version, $envelope, $correlationId);
                } catch (PromptHookFailure) {
                    // audited; legacy remains SoT — no domain write from live shadow
                }

                return $legacyResult;
            }

            return $this->engine->shadowWithoutProvider(
                $hookKey,
                $version,
                $envelope,
                $legacyExecute,
                $mapLegacyOutput,
                $correlationId,
            );
        }

        $result = $this->engine->execute($hookKey, $version, $envelope, $correlationId);
        if ($mapHookResult !== null) {
            return $mapHookResult($result);
        }

        return $result->output['value'] ?? null;
    }
}
