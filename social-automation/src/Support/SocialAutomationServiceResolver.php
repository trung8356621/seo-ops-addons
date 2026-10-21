<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Support;

use App\Models\Service;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Canonical Social Automation service reader — slug=social-automation.
 *
 * Addon installed ≠ Service active. Catalog row is created inactive;
 * only ops-server (or explicit local fixture) activates entitlement.
 */
final class SocialAutomationServiceResolver
{
    public const SLUG = 'social-automation';

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
            // Missing catalog row must not grant product access.
            return false;
        }

        if (! (bool) $service->is_active) {
            return false;
        }

        return $this->h1Allows(defaultWhenMissing: true);
    }

    private function h1Allows(bool $defaultWhenMissing): bool
    {
        if (! class_exists(\App\Services\H1\Enforcement\ServiceEntitlementGate::class)) {
            return $defaultWhenMissing;
        }

        try {
            /** @var \App\Services\H1\Enforcement\ServiceEntitlementGate $gate */
            $gate = app(\App\Services\H1\Enforcement\ServiceEntitlementGate::class);
            if (! $gate->enforcementActive()) {
                return $defaultWhenMissing;
            }

            return $gate->allows(self::SLUG);
        } catch (Throwable) {
            return $defaultWhenMissing;
        }
    }

    public function resolve(): SocialAutomationServiceConfig
    {
        $service = $this->service();
        $raw = is_array($service?->config) ? $service->config : [];
        $version = trim((string) ($raw['version'] ?? ''));
        if ($version === '') {
            $version = $this->manifestVersion();
        }

        $connection = SocialAutomationServiceConfig::CONNECTION;
        $databaseName = (string) config('database.connections.'.$connection.'.database', 'omi_social_automation');
        if ($databaseName === '' || strcasecmp($databaseName, 'omi_seo_ai') === 0 || strcasecmp($databaseName, 'omi_seeding') === 0) {
            $databaseName = 'omi_social_automation';
        }

        return new SocialAutomationServiceConfig(
            active: $this->isActive(),
            version: $version,
            database: [
                'connection' => $connection,
                'database' => $databaseName,
            ],
            persistence: SocialAutomationServiceConfig::PERSISTENCE_SERVER,
            storageSchemaVersion: SocialAutomationServiceConfig::STORAGE_SCHEMA_VERSION,
            rawConfig: $raw,
        );
    }

    /**
     * Ensure catalog row exists for services.apply / activation.
     * Never forces is_active=true — entitlement remains ops-server owned.
     */
    public function ensureCatalogRow(): void
    {
        if (! Schema::hasTable('services')) {
            return;
        }

        $existing = $this->service();
        if ($existing instanceof Service) {
            $dirty = false;
            if ((string) ($existing->db_connection ?? '') !== SocialAutomationServiceConfig::CONNECTION) {
                $existing->db_connection = SocialAutomationServiceConfig::CONNECTION;
                $dirty = true;
            }
            if ((string) ($existing->addon_namespace ?? '') === '') {
                $existing->addon_namespace = \Omnichannel\Addons\SocialAutomation\SocialAutomationServiceProvider::class;
                $dirty = true;
            }
            if ($dirty) {
                $existing->save();
            }

            return;
        }

        Service::query()->create([
            'name' => 'Social Automation',
            'slug' => self::SLUG,
            'addon_namespace' => \Omnichannel\Addons\SocialAutomation\SocialAutomationServiceProvider::class,
            'db_connection' => SocialAutomationServiceConfig::CONNECTION,
            'is_active' => false,
            'config' => [
                'enabled' => false,
                'version' => $this->manifestVersion(),
                'scope' => 'global',
                'database' => [
                    'connection' => SocialAutomationServiceConfig::CONNECTION,
                    'database' => 'omi_social_automation',
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
