<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Canonical;

final class PromptHookInputSchema
{
    /**
     * @param  array<string, array<string, mixed>>  $fields
     */
    public function __construct(public readonly array $fields) {}
}
