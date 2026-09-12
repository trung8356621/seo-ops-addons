<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Social\Ai\Tasks;

use Omnichannel\Addons\Social\Ai\Contracts\SocialAiTaskInterface;
use Omnichannel\Addons\Social\Ai\Exceptions\SocialAiValidationException;
use Throwable;

/**
 * Universal text-to-text Social AI task for comment generation.
 *
 * Receives short plain text context only.
 */
final class SocialCommentGenerateTask implements SocialAiTaskInterface
{
    public const TASK_KEY = 'social.comment.generate';
    public const CAPABILITY = 'text.generate';
    public const ROUTING_PROFILE = 'text.fast';

    public function taskKey(): string
    {
        return self::TASK_KEY;
    }

    public function capability(): string
    {
        return self::CAPABILITY;
    }

    public function routingProfile(): string
    {
        return self::ROUTING_PROFILE;
    }

    public function systemPrompt(): string
    {
        return <<<PROMPT
You generate short, natural Vietnamese social comments based only on the supplied text context.

Requirements:
- Write in Vietnamese.
- Match the supplied context.
- Adapt naturally to the supplied social platform.
- Do not invent unsupported facts.
- Use varied wording and sentence structure.
- Avoid obvious repeated patterns.
- Avoid sounding like spam or forced advertising.
- Keep each comment concise.
- Each comment must be under 300 words.
- Return exactly the requested number of comments.
PROMPT;
    }

    /**
     * Normalizes inputs into plain text context, social platform, and quantity.
     *
     * @param  array<string, mixed>  $rawInput
     * @return array{
     *     context: string,
     *     social: string,
     *     quantity: int
     * }
     */
    public function normalizeInput(array $rawInput): array
    {
        $context = trim((string) ($rawInput['context'] ?? $rawInput['content'] ?? $rawInput['full_text'] ?? ''));

        $social = trim((string) ($rawInput['social'] ?? $rawInput['platform'] ?? 'threads'));
        if ($social === '') {
            $social = 'threads';
        }

        $quantity = (int) ($rawInput['quantity'] ?? $rawInput['count'] ?? 3);
        $quantity = max(1, min(12, $quantity));

        return [
            'context' => $context,
            'social' => $social,
            'quantity' => $quantity,
        ];
    }

    /**
     * Builds the compiled prompt for canonical AI text execution.
     *
     * @param  array<string, mixed>  $normalized
     */
    public function buildCompiledPrompt(array $normalized): string
    {
        $context = (string) ($normalized['context'] ?? '');
        $social = (string) ($normalized['social'] ?? 'threads');
        $quantity = (int) ($normalized['quantity'] ?? 3);

        $systemPrompt = $this->systemPrompt();

        return <<<PROMPT
{$systemPrompt}

---
THÔNG TIN ĐẦU VÀO:
Nền tảng mạng xã hội: {$social}
Số lượng comment yêu cầu: {$quantity}

Ngữ cảnh nội dung:
{$context}

---
YÊU CẦU ĐỊNH DẠNG ĐẦU RA:
Trả về duy nhất định dạng JSON thuần (KHÔNG kèm markdown ```json hay văn bản giải thích nào khác) theo schema:
{
  "comments": [
    "Nội dung comment 1...",
    "Nội dung comment 2...",
    "..."
  ]
}

Số lượng phần tử trong mảng "comments" phải chính xác là {$quantity}.
Mỗi comment phải tự nhiên, mang góc nhìn thảo luận thật của người dùng mạng xã hội, dưới 300 từ.
PROMPT;
    }

    /**
     * Validates and parses raw AI output into a list of comment strings.
     *
     * @return list<string>
     *
     * @throws SocialAiValidationException
     */
    public function validateAndParseOutput(string $rawOutput, int $requestedQuantity): array
    {
        $clean = trim($rawOutput);
        if ($clean === '') {
            throw new SocialAiValidationException('AI trả về phản hồi rỗng.');
        }

        // Strip markdown code fences if present
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean) ?? $clean;
        $clean = preg_replace('/\s*```$/', '', $clean) ?? $clean;
        $clean = trim($clean);

        $parsed = null;
        try {
            $decoded = json_decode($clean, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $parsed = $decoded;
            }
        } catch (Throwable) {
            // Try extracting JSON substring if AI included preamble
            if (preg_match('/\{[\s\S]*"comments"[\s\S]*\}/i', $clean, $matches)) {
                try {
                    $decoded = json_decode($matches[0], true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $parsed = $decoded;
                    }
                } catch (Throwable) {
                    $parsed = null;
                }
            }
        }

        $comments = [];

        if (is_array($parsed) && isset($parsed['comments']) && is_array($parsed['comments'])) {
            foreach ($parsed['comments'] as $c) {
                if (is_string($c)) {
                    $item = trim($c);
                    if ($item !== '') {
                        $comments[] = $item;
                    }
                }
            }
        }

        // Fallback: if JSON failed but text has numbered list
        if ($comments === []) {
            $lines = preg_split('/\r\n|\r|\n/', $clean);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $trimmed = trim($line);
                    // Match "1. ...", "- ...", or similar
                    $stripped = preg_replace('/^(?:\d+[\.\)]|\-|\*)\s*/', '', $trimmed) ?? $trimmed;
                    $stripped = trim($stripped, " \t\n\r\0\x0B\"'");
                    if ($stripped !== '' && ! str_starts_with($stripped, '{') && ! str_starts_with($stripped, '}') && ! str_contains($stripped, '"comments"')) {
                        $comments[] = $stripped;
                    }
                }
            }
        }

        if ($comments === []) {
            throw new SocialAiValidationException('Không thể bóc tách bình luận hợp lệ từ phản hồi của AI.');
        }

        // Ensure requested quantity
        if (count($comments) > $requestedQuantity) {
            $comments = array_slice($comments, 0, $requestedQuantity);
        }

        return array_values($comments);
    }
}
