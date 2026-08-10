<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Migration;

/**
 * Đếm sample shadow parity theo caller (process memory + log). Staging validation.
 */
final class AutomationParitySampleRecorder
{
    /** @var array<string, array{match: int, mismatch: int, samples: int}> */
    private array $stats = [];

    public function record(string $callerKey, bool $matched): void
    {
        if (! isset($this->stats[$callerKey])) {
            $this->stats[$callerKey] = ['match' => 0, 'mismatch' => 0, 'samples' => 0];
        }

        $this->stats[$callerKey]['samples']++;
        if ($matched) {
            $this->stats[$callerKey]['match']++;
        } else {
            $this->stats[$callerKey]['mismatch']++;
        }
    }

    /**
     * @return array{match: int, mismatch: int, samples: int}
     */
    public function forCaller(string $callerKey): array
    {
        return $this->stats[$callerKey] ?? ['match' => 0, 'mismatch' => 0, 'samples' => 0];
    }

    /**
     * @return array<string, array{match: int, mismatch: int, samples: int}>
     */
    public function all(): array
    {
        return $this->stats;
    }

    public function reset(?string $callerKey = null): void
    {
        if ($callerKey === null) {
            $this->stats = [];

            return;
        }

        unset($this->stats[$callerKey]);
    }
}
