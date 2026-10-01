<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

use App\IndustryContext\IndustryAuxiliarySchema;
use App\IndustryContext\IndustryContextSchema;
use InvalidArgumentException;

final class IndustryContextPromptCompiler
{
    public static function compile(string $editablePrompt, ?string $notes = null): string
    {
        return self::compileForType($editablePrompt, 'core', $notes);
    }

    public static function compileForType(string $editablePrompt, string $type, ?string $notes = null): string
    {
        $prompt = rtrim($editablePrompt);
        if (trim((string) $notes) !== '') {
            $prompt .= "\n\nTemporary generation notes:\n".trim((string) $notes);
        }

        [$heading, $schema] = match ($type) {
            'core' => ['Canonical Industry Context JSON Schema (the single source of truth):', IndustryContextSchema::json()],
            'discovery' => ['Canonical Industry Discovery & Attention JSON Schema (the single source of truth):', IndustryAuxiliarySchema::json(IndustryAuxiliarySchema::DISCOVERY)],
            'breakout' => ['Canonical Industry Breakout JSON Schema (the single source of truth):', IndustryAuxiliarySchema::json(IndustryAuxiliarySchema::BREAKOUT)],
            default => throw new InvalidArgumentException("Unknown Industry Context prompt type [{$type}]."),
        };

        return $prompt."\n\n{$heading}\n".$schema;
    }
}
