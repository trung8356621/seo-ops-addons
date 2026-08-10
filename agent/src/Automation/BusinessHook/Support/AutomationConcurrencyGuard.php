<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Support;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Illuminate\Support\Facades\Cache;

final class AutomationConcurrencyGuard
{
    private const LOCK_SECONDS = 600;

    /**
     * @param  array<string, mixed>  $sources
     */
    public function acquire(AutomationRule $rule, array $sources): ?string
    {
        $settings = $rule->settings ?? [];
        $template = trim((string) ($settings['concurrency_key'] ?? ''));
        if ($template === '') {
            return null;
        }

        $key = $this->resolveKey($template, $sources);
        if ($key === '') {
            return null;
        }

        $lockKey = 'automation:concurrency:'.$key;
        $lock = Cache::lock($lockKey, self::LOCK_SECONDS);
        if (! $lock->get()) {
            return null;
        }

        return $lockKey;
    }

    public function release(?string $lockKey): void
    {
        if ($lockKey === null || $lockKey === '') {
            return;
        }

        Cache::lock($lockKey)->forceRelease();
    }

    /**
     * @param  array<string, mixed>  $sources
     */
    private function resolveKey(string $template, array $sources): string
    {
        $replacements = [
            'subject.id' => (string) ($sources['subject']['id'] ?? $sources['event']['subject_id'] ?? ''),
            'site_id' => (string) ($sources['event']['site_id'] ?? ''),
            'project_id' => (string) ($sources['event']['project_id'] ?? ''),
            'article_id' => (string) ($sources['payload']['article_id'] ?? $sources['subject']['id'] ?? ''),
        ];

        $key = $template;
        foreach ($replacements as $token => $value) {
            $key = str_replace('{'.$token.'}', $value, $key);
            $key = str_replace($token, $value, $key);
        }

        return trim($key);
    }
}
