<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Model;

/**
 * Single model-visible input. Send and Copy both read this object.
 */
readonly class PreparedModelInput
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

    public function exportForManualChat(): string
    {
        $systemInstructions = [];
        $userPayloads = [];

        foreach ($this->messages as $message) {
            $role = strtolower((string) ($message['role'] ?? 'user'));
            $content = (string) ($message['content'] ?? '');
            if ($role === 'system') {
                $systemInstructions[] = $content;
            } else {
                $userPayloads[] = $content;
            }
        }

        $systemText = trim(implode("\n\n", $systemInstructions));
        $userText = trim(implode("\n\n", $userPayloads));

        $sections = [];

        $sections[] = <<<INSTRUCTION
You are acting as an internal AI backend engine for an SEO Operations Agent.
Follow the system instructions and user evidence below to produce the final response.

CRITICAL INSTRUCTIONS FOR THIS CHAT TURN:
1. Respond ONLY with a single valid AgentResponse JSON object.
2. Do NOT output conversational greetings, preamble, explanations, apologies, or markdown code fences (no ```json or ```).
3. Output raw JSON starting with '{' and ending with '}'.
4. The JSON must adhere strictly to the response contract and selected template specified in the payload.
INSTRUCTION;

        if ($systemText !== '') {
            $sections[] = "=== SYSTEM INSTRUCTIONS ===\n".$systemText;
        }

        if ($userText !== '') {
            $sections[] = "=== USER REQUEST & RETRIEVAL EVIDENCE ===\n".$userText;
        }

        $sections[] = <<<FINAL
=== OUTPUT REQUIREMENT ===
Return ONLY the raw AgentResponse JSON object now. Do not include markdown code fences or any conversational text.
FINAL;

        return implode("\n\n", $sections);
    }
}
