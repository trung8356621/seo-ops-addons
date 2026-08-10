<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Extension\Contracts;

interface PromptHookContributor
{
    /**
     * @return list<array{key: string, version: string, meta: array<string, mixed>}>
     */
    public function hooks(): array;
}
