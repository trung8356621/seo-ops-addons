<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Exceptions;

use RuntimeException;

class PromptRunException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly array $context = [],
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function userMessage(): string
    {
        $user = $this->context['user_message'] ?? null;

        return is_string($user) && $user !== '' ? $user : $this->getMessage();
    }

    public function technicalDetails(): string
    {
        $tech = $this->context['technical_details'] ?? null;

        return is_string($tech) && $tech !== '' ? $tech : $this->getMessage();
    }

    public function classification(): ?string
    {
        $classification = $this->context['classification'] ?? null;

        return is_string($classification) && $classification !== '' ? $classification : null;
    }

    public function isRetryable(): bool
    {
        return (bool) ($this->context['retryable'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function audit(): array
    {
        $audit = $this->context['audit'] ?? null;

        return is_array($audit) ? $audit : [];
    }
}
