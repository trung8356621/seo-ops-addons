<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Application\Publishing;

use InvalidArgumentException;

/**
 * Application-facing publisher registry — Core chỉ biết interface ContentPublisher.
 * Duplicate keys fail closed (parity Extension PublisherRegistry).
 */
final class ContentPublisherRegistry
{
    /** @var array<string, ContentPublisher> */
    private array $publishers = [];

    public function register(string $key, ContentPublisher $publisher): void
    {
        if (isset($this->publishers[$key])) {
            throw new InvalidArgumentException("Content publisher [{$key}] already registered.");
        }

        $this->publishers[$key] = $publisher;
    }

    public function get(string $key): ?ContentPublisher
    {
        return $this->publishers[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->publishers[$key]);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->publishers);
    }
}
