<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Data;

final class PromptHookExecutionResult
{
    /**
     * @param  array{format: string, raw: string, value: string}  $output
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $hook,
        public readonly array $output,
        public readonly ?int $promptResultId = null,
        public readonly array $meta = [],
    ) {}

    /**
     * @return array{hook: string, output: array{format: string, raw: string, value: string}}
     */
    public function toApiData(): array
    {
        return [
            'hook' => $this->hook,
            'output' => $this->output,
        ];
    }
}
