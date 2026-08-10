<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

/** Runtime result — no provider SDK; no domain entity. */
final class PromptHookRuntimeResult
{
    /**
     * @param  array{type: string, raw: string, value: mixed, warnings: list<string>}  $output
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $hookKey,
        public readonly string $hookVersion,
        public readonly string $mode,
        public readonly array $output,
        public readonly ?string $correlationId = null,
        public readonly ?string $auditFingerprint = null,
        public readonly array $meta = [],
    ) {}
}
