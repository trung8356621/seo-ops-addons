<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

use App\IndustryContext\IndustryContextSchema;
use InvalidArgumentException;
use RuntimeException;

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

    public static function runnablePrompt(
        string $contextName,
        ?string $language = 'vi',
        ?string $market = null,
        ?string $notes = null,
    ): string {
        $contextName = trim($contextName);
        if ($contextName === '') {
            throw new InvalidArgumentException('Industry Context name is required.');
        }

        $language = trim((string) $language);
        $language = $language !== '' ? $language : 'vi';
        $market = trim((string) $market);

        $seed = 'for '.self::quoted($contextName).' in '.self::quoted($language);
        if ($market !== '') {
            $seed .= ' for market '.self::quoted($market);
        }

        $template = DefaultIndustryContextPromptInstaller::canonicalDefaultMarkdown();
        $instruction = preg_replace_callback(
            '/for \{\{context_name\}\} in \{\{language\}\} for \{\{market\}\}/',
            static fn (): string => $seed,
            $template,
            1,
            $replacementCount,
        );
        if (! is_string($instruction) || $replacementCount !== 1) {
            throw new RuntimeException('Canonical Industry Context prompt seed placeholders are invalid.');
        }

        return self::compile($instruction, $notes);
    }

    private static function quoted(string $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
