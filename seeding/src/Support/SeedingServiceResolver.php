<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Support;

use App\Models\ClientControlState;
use App\Models\Service;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical Seeding service reader — single place for Service slug=seeding resolution.
 */
final class SeedingServiceResolver
{
    public const SLUG = 'seeding';

    public function service(): ?Service
    {
        if (! Schema::hasTable('services')) {
            return null;
        }

        $row = Service::query()->where('slug', self::SLUG)->first();

        return $row instanceof Service ? $row : null;
    }

    public function isActive(): bool
    {
        $service = $this->service();
        if (! $service instanceof Service) {
            // Catalog not synced yet — allow when addon boots via register_early (local/dev).
            return true;
        }

        return (bool) $service->is_active;
    }

    public function resolve(): SeedingServiceConfig
    {
        $service = $this->service();
        $raw = is_array($service?->config) ? $service->config : [];
        $version = trim((string) ($raw['version'] ?? ''));
        if ($version === '') {
            $version = $this->manifestVersion();
        }

        $connection = SeedingServiceConfig::CONNECTION;
        $databaseName = (string) config('database.connections.'.$connection.'.database', 'omi_seeding');
        if ($databaseName === '' || strcasecmp($databaseName, 'omi_seo_ai') === 0) {
            $databaseName = 'omi_seeding';
        }

        return new SeedingServiceConfig(
            active: $this->isActive(),
            version: $version,
            database: [
                'connection' => $connection,
                'database' => $databaseName,
            ],
            persistence: SeedingServiceConfig::PERSISTENCE_LOCAL,
            storageSchemaVersion: SeedingServiceConfig::STORAGE_SCHEMA_VERSION,
            rawConfig: $raw,
        );
    }

    /**
     * Stable client namespace for localStorage scoping.
     */
    public function installationNamespace(): string
    {
        if (Schema::hasTable('client_control_state')) {
            $id = ClientControlState::query()->orderBy('id')->value('installation_id');
            if (is_string($id) && $id !== '') {
                return $id;
            }
        }

        $fallback = trim((string) config('app.key', ''));
        if ($fallback !== '') {
            return 'app:'.substr(hash('sha256', $fallback), 0, 16);
        }

        return 'app:local';
    }

    /**
     * Ensure catalog row exists for services.apply / activation.
     * Does not force is_active=true on existing rows.
     */
    public function ensureCatalogRow(): void
    {
        if (! Schema::hasTable('services')) {
            return;
        }

        $existing = $this->service();
        if ($existing instanceof Service) {
            $dirty = false;
            if ((string) ($existing->db_connection ?? '') !== SeedingServiceConfig::CONNECTION) {
                $existing->db_connection = SeedingServiceConfig::CONNECTION;
                $dirty = true;
            }
            if ((string) ($existing->addon_namespace ?? '') === '') {
                $existing->addon_namespace = \Omnichannel\Addons\Seeding\SeedingServiceProvider::class;
                $dirty = true;
            }
            if ($dirty) {
                $existing->save();
            }

            return;
        }

        Service::query()->create([
            'name' => 'Seeding',
            'slug' => self::SLUG,
            'addon_namespace' => \Omnichannel\Addons\Seeding\SeedingServiceProvider::class,
            'db_connection' => SeedingServiceConfig::CONNECTION,
            'is_active' => true,
            'config' => [
                'enabled' => true,
                'version' => $this->manifestVersion(),
                'database' => [
                    'connection' => SeedingServiceConfig::CONNECTION,
                    'database' => 'omi_seeding',
                ],
            ],
        ]);
    }

    private function manifestVersion(): string
    {
        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'addon.json';
        if (! is_file($path)) {
            return '0.0.0';
        }

        $meta = json_decode((string) file_get_contents($path), true);

        return is_array($meta) ? trim((string) ($meta['version'] ?? '0.0.0')) : '0.0.0';
    }
}
