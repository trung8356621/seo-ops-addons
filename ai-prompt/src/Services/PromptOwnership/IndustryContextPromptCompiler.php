<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

use App\IndustryContext\IndustryContextSchema;

final class IndustryContextPromptCompiler
{
    public static function compile(string $editablePrompt, ?string $notes = null): string
    {
        $prompt = rtrim($editablePrompt);
        if (trim((string) $notes) !== '') {
            $prompt .= "\n\nTemporary generation notes:\n".trim((string) $notes);
        }

        return $prompt
            ."\n\nCanonical Industry Context JSON Schema (the single source of truth):\n"
            .IndustryContextSchema::json();
    }

    public static function runnablePrompt(string $contextName, string $language, string $market, ?string $notes = null): string
    {
        $instruction = strtr(DefaultIndustryContextPromptInstaller::canonicalDefaultMarkdown(), [
            '{{context_name}}' => $contextName,
            '{{language}}' => $language,
            '{{market}}' => $market,
        ]);

        return self::compile($instruction, $notes);
    }
}
