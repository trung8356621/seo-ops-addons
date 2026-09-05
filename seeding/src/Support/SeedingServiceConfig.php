<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Support;

/**
 * Immutable resolved Seeding service configuration (no secrets).
 *
 * @phpstan-type DatabaseConfig array{connection: string, database: string}
 */
final class SeedingServiceConfig
{
    public const CONNECTION = 'omi_seeding';

    public const PERSISTENCE_LOCAL = 'local';

    public const STORAGE_SCHEMA_VERSION = 1;

    /**
     * @param  array<string, mixed>  $rawConfig
     * @param  DatabaseConfig  $database
     */
    public function __construct(
        public readonly bool $active,
        public readonly string $version,
        public readonly array $database,
        public readonly string $persistence,
        public readonly int $storageSchemaVersion,
        public readonly array $rawConfig = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->active,
            'version' => $this->version,
            'persistence' => $this->persistence,
            'storage_schema_version' => $this->storageSchemaVersion,
            'database' => [
                'connection' => $this->database['connection'],
                'database' => $this->database['database'],
            ],
        ];
    }
}
