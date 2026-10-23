<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\ConfigurationSync;

/**
 * Local ACK / pending-retry state. Never stores machine secrets.
 */
final class ConfigurationSyncStateStore
{
    public function __construct(
        private readonly ?string $path = null,
    ) {}

    public function path(): string
    {
        if ($this->path !== null && $this->path !== '') {
            return $this->path;
        }

        $configured = config('configuration-sync.state_path');

        return is_string($configured) && $configured !== ''
            ? $configured
            : storage_path('app/configuration-sync/seo.json');
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $path = $this->path();
        if (! is_file($path)) {
            return $this->emptyState();
        }
        $raw = file_get_contents($path);
        if (! is_string($raw) || trim($raw) === '') {
            return $this->emptyState();
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->emptyState();
        }

        return is_array($decoded) ? array_merge($this->emptyState(), $decoded) : $this->emptyState();
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function write(array $state): void
    {
        $path = $this->path();
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new ConfigurationSyncException('state_unwritable', 'Configuration sync state directory is not writable.');
        }
        $payload = array_merge($this->emptyState(), $state);
        unset($payload['machine_secret'], $payload['secret'], $payload['api_key']);
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($json)) {
            throw new ConfigurationSyncException('state_unwritable', 'Unable to encode configuration sync state.');
        }
        $tmp = $path.'.tmp';
        if (file_put_contents($tmp, $json) === false) {
            throw new ConfigurationSyncException('state_unwritable', 'Unable to write configuration sync state.');
        }
        if (! rename($tmp, $path)) {
            @unlink($tmp);
            throw new ConfigurationSyncException('state_unwritable', 'Unable to promote configuration sync state.');
        }
    }

    public function recordResponseNonce(string $credentialId, string $nonce): void
    {
        $key = hash('sha256', $credentialId.'|'.$nonce);
        $this->withLock($this->path().'.lock', function () use ($key): void {
            $state = $this->read();
            $seen = is_array($state['response_nonces'] ?? null) ? $state['response_nonces'] : [];
            if (in_array($key, $seen, true)) {
                throw new ConfigurationSyncException('replay_detected', 'Server response nonce was already consumed.');
            }
            $seen[] = $key;
            $state['response_nonces'] = array_slice($seen, -200);
            $this->write($state);
        });
    }

    /**
     * @template T
     * @param  callable(array<string, mixed>): T  $callback
     * @return T
     */
    public function exclusive(callable $callback): mixed
    {
        return $this->withLock($this->path().'.lock', function () use ($callback): mixed {
            $state = $this->read();
            $result = $callback($state);
            $this->write($state);

            return $result;
        });
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    private function withLock(string $lockPath, callable $callback): mixed
    {
        $dir = dirname($lockPath);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new ConfigurationSyncException('state_unwritable', 'Configuration sync state directory is not writable.');
        }
        $handle = fopen($lockPath, 'c');
        if ($handle === false) {
            throw new ConfigurationSyncException('sync_busy', 'Configuration sync lock could not be opened.');
        }
        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new ConfigurationSyncException('sync_busy', 'Configuration sync is already running.');
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyState(): array
    {
        return [
            'bootstrap' => null,
            'package' => null,
            'pending' => null,
            'last_ack' => null,
            'last_compare' => null,
            'response_nonces' => [],
        ];
    }
}
