<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent;

use Illuminate\Support\Facades\Cache;

/**
 * Config-driven rate limits for Agent Gateway.
 */
final class ContentProjectAgentRateLimiter
{
    /**
     * @return array{max_attempts: int, decay_seconds: int}
     */
    private function limits(string $key): array
    {
        $config = config('seo-content-ai.content_project_agent.rate_limits.'.$key);
        if (! is_array($config)) {
            $defaults = [
                'request' => ['max_attempts' => 120, 'decay_seconds' => 60],
                'poll' => ['max_attempts' => 30, 'decay_seconds' => 60],
                'create' => ['max_attempts' => 10, 'decay_seconds' => 3600],
                'archive' => ['max_attempts' => 5, 'decay_seconds' => 3600],
            ];
            $config = $defaults[$key] ?? ['max_attempts' => 60, 'decay_seconds' => 60];
        }

        return [
            'max_attempts' => max(1, (int) ($config['max_attempts'] ?? 60)),
            'decay_seconds' => max(1, (int) ($config['decay_seconds'] ?? 60)),
        ];
    }

    public function checkRequest(AgentExecutionContext $context): ?AgentCapabilityResult
    {
        return $this->hit('request', $this->actorKey($context));
    }

    public function checkPoll(AgentExecutionContext $context, string $operationRef): ?AgentCapabilityResult
    {
        $minSeconds = 5;
        if (function_exists('config')) {
            try {
                $minSeconds = max(1, (int) config('seo-content-ai.content_project_agent.poll_min_seconds', 5));
            } catch (\Throwable) {
                $minSeconds = 5;
            }
        }

        $cooldownKey = 'seo.cp.agent.poll.cooldown.'.sha1($this->actorKey($context).'|'.$operationRef);
        $last = Cache::get($cooldownKey);
        if (is_int($last) || is_numeric($last)) {
            $elapsed = time() - (int) $last;
            if ($elapsed < $minSeconds) {
                return AgentCapabilityResult::fail(
                    AgentErrorCodes::RATE_LIMITED,
                    'Poll too frequent for this operation.',
                    meta: ['retry_after' => $minSeconds - $elapsed],
                );
            }
        }
        Cache::put($cooldownKey, time(), max(60, $minSeconds * 10));

        return $this->hit('poll', $this->actorKey($context).':'.$operationRef);
    }

    public function checkCreate(AgentExecutionContext $context): ?AgentCapabilityResult
    {
        return $this->hit('create', $this->actorKey($context));
    }

    public function checkArchive(AgentExecutionContext $context): ?AgentCapabilityResult
    {
        return $this->hit('archive', $this->actorKey($context));
    }

    private function actorKey(AgentExecutionContext $context): string
    {
        return sha1($context->actorRef.'|'.$context->siteRef.'|'.(string) ($context->resolvedSiteId ?? 0));
    }

    private function hit(string $bucket, string $key): ?AgentCapabilityResult
    {
        $limits = $this->limits($bucket);
        $cacheKey = 'seo.cp.agent.rl.'.$bucket.'.'.$key;
        $attempts = (int) Cache::get($cacheKey, 0);

        if ($attempts >= $limits['max_attempts']) {
            return AgentCapabilityResult::fail(
                AgentErrorCodes::RATE_LIMITED,
                'Rate limit exceeded.',
                meta: [
                    'retry_after' => $limits['decay_seconds'],
                ],
            );
        }

        Cache::put($cacheKey, $attempts + 1, $limits['decay_seconds']);

        return null;
    }
}
