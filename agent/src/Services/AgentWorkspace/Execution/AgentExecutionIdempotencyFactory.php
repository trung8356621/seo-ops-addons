<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution;

use Illuminate\Support\Str;

/**
 * Server-generated idempotency keys for Agent Workspace executions.
 * Browser must never supply the key.
 */
final class AgentExecutionIdempotencyFactory
{
    public function make(string $executionRef, int $attempt = 1): string
    {
        return 'awex:'.$executionRef.':a'.$attempt.':'.Str::lower((string) Str::ulid());
    }

    public function mask(?string $key): string
    {
        if ($key === null || $key === '') {
            return '—';
        }

        if (strlen($key) <= 12) {
            return '***';
        }

        return substr($key, 0, 8).'…'.substr($key, -4);
    }
}
