<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Normalize provider finish/stop reasons + HTTP/billing evidence into stable terminal codes.
 * Never invents balance/credit — only maps exposed signals.
 */
final class AiProviderTerminalReasonNormalizer
{
    /**
     * OpenAI / OpenRouter / DeepSeek / Gemini / Anthropic length stops.
     *
     * @var list<string>
     */
    private const LENGTH_STOP_REASONS = [
        'length',
        'max_tokens',
        'max_token',
        'max_output_tokens',
        'max_output_token',
        'output_token_limit',
        'token_limit',
    ];

    /**
     * @param  array<string, mixed>  $usageOrResponse
     */
    public function normalizeFromUsage(
        array $usageOrResponse,
        bool $truncatedFlag = false,
        ?int $httpStatus = null,
        ?string $errorMessage = null,
        ?string $providerErrorCode = null,
    ): ?AiProviderTerminalReason {
        $fromHttp = $this->normalizeFromHttpFailure($httpStatus, $errorMessage, $providerErrorCode);
        if ($fromHttp !== null) {
            return $fromHttp;
        }

        if ($truncatedFlag || $this->incompleteLooksTruncated($usageOrResponse)) {
            return AiProviderTerminalReason::OutputTruncated;
        }

        $finishReason = $this->extractFinishReason($usageOrResponse);
        if ($finishReason === null || $finishReason === '') {
            return null;
        }

        if ($this->isLengthStopReason($finishReason)) {
            return AiProviderTerminalReason::OutputTruncated;
        }

        if ($this->isCompletedStopReason($finishReason)) {
            return AiProviderTerminalReason::Completed;
        }

        $lower = strtolower($finishReason);
        if (in_array($lower, ['safety', 'content_filter', 'recitation', 'blocklist', 'prohibited_content'], true)) {
            return null;
        }

        if (in_array($lower, ['resource_exhausted', 'resourceexhausted', 'unavailable'], true)) {
            return AiProviderTerminalReason::ProviderResourceLimited;
        }

        return null;
    }

    public function normalizeFromHttpFailure(
        ?int $httpStatus,
        ?string $errorMessage,
        ?string $providerErrorCode = null,
    ): ?AiProviderTerminalReason {
        $lower = strtolower(trim((string) $errorMessage));
        $code = strtolower(trim((string) $providerErrorCode));

        if ($httpStatus === 402
            || str_contains($lower, 'insufficient credit')
            || str_contains($lower, 'insufficient credits')
            || str_contains($lower, 'requires more credits')
            || str_contains($lower, 'insufficient balance')
            || str_contains($lower, 'no credits')
            || str_contains($lower, 'zero balance')
            || in_array($code, ['insufficient_quota', 'insufficient_credits', 'insufficient_balance', 'payment_required'], true)
        ) {
            return AiProviderTerminalReason::InsufficientCredit;
        }

        if ($httpStatus === 429
            || str_contains($lower, 'rate limit')
            || str_contains($lower, 'too many requests')
            || in_array($code, ['rate_limit', 'rate_limit_exceeded', 'too_many_requests'], true)
        ) {
            if (str_contains($lower, 'quota') || str_contains($code, 'quota')) {
                return AiProviderTerminalReason::QuotaLimited;
            }

            return AiProviderTerminalReason::RateLimited;
        }

        if (str_contains($lower, 'quota')
            || in_array($code, ['insufficient_quota', 'organization_quota', 'account_quota'], true)
        ) {
            return AiProviderTerminalReason::QuotaLimited;
        }

        if (str_contains($lower, 'resource exhausted')
            || str_contains($lower, 'resource_exhausted')
            || $code === 'resource_exhausted'
        ) {
            return AiProviderTerminalReason::ProviderResourceLimited;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $usageOrResponse
     */
    public function extractFinishReason(array $usageOrResponse): ?string
    {
        foreach (['finish_reason', 'finishReason', 'stop_reason', 'stopReason'] as $key) {
            if (! array_key_exists($key, $usageOrResponse)) {
                continue;
            }
            $raw = $usageOrResponse[$key];
            if (is_string($raw) || is_numeric($raw)) {
                $value = trim((string) $raw);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        $incomplete = $usageOrResponse['incomplete_details'] ?? null;
        if (is_array($incomplete)) {
            $reason = $incomplete['reason'] ?? null;
            if (is_string($reason) && trim($reason) !== '') {
                return trim($reason);
            }
        }

        return null;
    }

    public function isLengthStopReason(?string $finishReason): bool
    {
        $reason = strtolower(trim((string) $finishReason));
        if ($reason === '') {
            return false;
        }

        if (in_array($reason, self::LENGTH_STOP_REASONS, true)) {
            return true;
        }

        // Gemini / some SDKs emit MAX_TOKENS in SCREAMING_SNAKE (lowercased above).
        return str_ends_with($reason, '_max_tokens');
    }

    /**
     * Persist finish_reason + provider_terminal_reason onto usage for history/attempts.
     *
     * @param  array<string, mixed>  $usage
     * @return array<string, mixed>
     */
    public function stampUsage(array $usage, bool $truncatedFlag = false): array
    {
        $finishReason = $this->extractFinishReason($usage);
        if ($finishReason !== null) {
            $usage['finish_reason'] = $finishReason;
        }

        $terminal = $this->normalizeFromUsage($usage, truncatedFlag: $truncatedFlag);
        if ($terminal === null && $finishReason !== null && $this->isCompletedStopReason($finishReason)) {
            $terminal = AiProviderTerminalReason::Completed;
        }
        if ($truncatedFlag || ($finishReason !== null && $this->isLengthStopReason($finishReason))) {
            $terminal = AiProviderTerminalReason::OutputTruncated;
        }
        if ($terminal !== null) {
            $usage['provider_terminal_reason'] = $terminal->value;
        }

        return $usage;
    }

    public function isCompletedStopReason(?string $finishReason): bool
    {
        $reason = strtolower(trim((string) $finishReason));

        return in_array($reason, [
            'stop',
            'end_turn',
            'stop_sequence',
            'tool_use',
            'tool_calls',
            'function_call',
            // Gemini
            'stop',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $usageOrResponse
     */
    private function incompleteLooksTruncated(array $usageOrResponse): bool
    {
        $status = strtolower(trim((string) ($usageOrResponse['response_status'] ?? $usageOrResponse['status'] ?? '')));
        if ($status === 'incomplete') {
            $incomplete = $usageOrResponse['incomplete_details'] ?? null;
            if (! is_array($incomplete)) {
                return true;
            }
            $reason = strtolower(trim((string) ($incomplete['reason'] ?? '')));

            return $reason === ''
                || $this->isLengthStopReason($reason)
                || in_array($reason, ['max_output_tokens', 'content_filter', 'length'], true);
        }

        return false;
    }
}
