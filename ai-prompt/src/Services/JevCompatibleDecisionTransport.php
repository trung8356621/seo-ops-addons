<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;

/**
 * Boundary for a future Jev-compatible base URL (Laya / self-host).
 * AI Connection has no provider whose protocol is the Decisions API
 * with its own base URL, so this transport stays unavailable.
 */
final class JevCompatibleDecisionTransport implements DecisionTransport
{
    public const GAP_CODE = 'laya_connection_unsupported';

    public function supports(ApiConnection $connection, string $model): bool
    {
        return false;
    }

    public function endpoint(): string
    {
        return '';
    }

    public function submit(ApiConnection $connection, string $model, string $state): array
    {
        throw new \RuntimeException(self::GAP_CODE);
    }

    /**
     * @return array{use_case: string, missing: string, request: string, response: string, why: string}
     */
    public static function gap(): array
    {
        return [
            'use_case' => 'Laya or another self-hosted Jev-compatible endpoint',
            'missing' => 'AI Connection has no provider whose protocol is the Decisions / System One API and whose base URL is configurable. An OpenRouter base URL override still belongs to the OpenRouter chat template.',
            'request' => 'A connection provider with base_url plus POST {base}/api/alpha/decisions, authenticated by that connection key.',
            'response' => 'The Decisions API answers object: model, answers.{noul|choice|score}, usage.',
            'why' => 'Laya is not an OpenRouter model id. Agent Runtime must not pretend an OpenRouter row is Laya.',
        ];
    }
}
