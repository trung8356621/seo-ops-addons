<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Model;

/**
 * Single model-visible input. Send and Copy both read this object.
 */
final readonly class PreparedModelInput
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    public function __construct(
        public string $stage,
        public array $messages,
    ) {}

    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    public static function make(string $stage, array $messages, SecretRedactor $redactor): self
    {
        $clean = [];
        foreach ($messages as $message) {
            $clean[] = [
                'role' => (string) ($message['role'] ?? 'user'),
                'content' => $redactor->redact((string) ($message['content'] ?? '')),
            ];
        }

        return new self($stage, $clean);
    }

    public function exportText(): string
    {
        $chunks = [];
        foreach ($this->messages as $message) {
            $chunks[] = strtoupper($message['role'])."\n".$message['content'];
        }

        return implode("\n\n", $chunks);
    }
}
