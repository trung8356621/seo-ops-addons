<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Contracts;

/**
 * Prose / structured answer completion through the text routing profile.
 * This is not a Prompt Admin record.
 */
interface AnswerTextCompletion
{
    public function complete(int $userId, string $prompt, int $maxOutputTokens): string;
}
