<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Extension;

use InvalidArgumentException;
use JsonException;

final class ExtensionManifest
{
    /**
     * @param  list<string>  $providers
     * @param  list<string>  $capabilities
     * @param  list<string>  $requires
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $version,
        public readonly int $sdk,
        public readonly string $provider,
        public readonly array $providers = [],
        public readonly array $capabilities = [],
        public readonly array $requires = [],
        public readonly bool $enabled = true,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $id = trim((string) ($data['id'] ?? ''));
        $provider = trim((string) ($data['provider'] ?? ''));

        if ($id === '' || $provider === '') {
            throw new InvalidArgumentException('Extension manifest requires id and provider.');
        }

        return new self(
            id: $id,
            name: trim((string) ($data['name'] ?? $id)),
            version: trim((string) ($data['version'] ?? '0.0.0')),
            sdk: (int) ($data['sdk'] ?? 0),
            provider: $provider,
            providers: self::stringList($data['providers'] ?? []),
            capabilities: self::stringList($data['capabilities'] ?? []),
            requires: self::stringList($data['requires'] ?? []),
            enabled: (bool) ($data['enabled'] ?? true),
        );
    }

    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Extension manifest not found: {$path}");
        }

        $raw = (string) file_get_contents($path);

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException("Invalid plugin.json at {$path}: {$e->getMessage()}", 0, $e);
        }

        return self::fromArray($decoded);
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }
}
