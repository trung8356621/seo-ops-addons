<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

use App\IndustryContext\IndustryContextSchema;

final class IndustryContextPromptCompiler
{
    public static function compile(string $editablePrompt): string
    {
        return rtrim($editablePrompt)
            ."\n\nCanonical Industry Context JSON Schema (the single source of truth):\n"
            .IndustryContextSchema::json();
    }
}
