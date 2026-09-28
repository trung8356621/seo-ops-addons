<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\SettingsTransfer;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\Services\AiModelFamilyCatalog;
use Omnichannel\Addons\AiPrompt\Services\AiResilienceSettingsService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\ImageFamilySelectionAdapter;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiUsageMode;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;

final class AiCenterSettingsSection implements PortableSettingsSection
{
    public const KEY_PREFERRED_PROVIDER = 'preferred_provider';

    public const CONFIGURATION_METADATA_WHITELIST = [
        'provider_template',
        'routing_priority',
        'base_url',
        'allow_base_url_override',
        'short_code',
        'display_name',
        'description',
        'notes',
        'custom_headers',
        'auth_type',
        'header_name',
        'query_param',
    ];

    /**
     * Whitelist portable configuration metadata only.
     * Rejects all runtime/operational health and transient cache keys.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public static function sanitizeConnectionMetadata(array $metadata): array
    {
        $clean = [];
        foreach (self::CONFIGURATION_METADATA_WHITELIST as $key) {
            if (array_key_exists($key, $metadata) && $metadata[$key] !== null) {
                $clean[$key] = $metadata[$key];
            }
        }

        // Drop secrets unconditionally
        unset($clean['api_key'], $clean['token'], $clean['password'], $clean['secret']);

        if (isset($clean['provider_template']) && is_array($clean['provider_template'])) {
            unset(
                $clean['provider_template']['api_key'],
                $clean['provider_template']['token'],
                $clean['provider_template']['password'],
                $clean['provider_template']['secret']
            );
        }

        return $clean;
    }

    public function key(): string
    {
        return 'ai';
    }

    public function export(int $userId): array
    {
        $settings = app(SeoCreateArticleSettingsService::class);
        $targets = app(AiRoutingTargetService::class);
        $resilience = app(AiResilienceSettingsService::class);
        $adapter = new ImageFamilySelectionAdapter();
        $profiles = [];
        foreach (AiExecutionProfile::cases() as $profile) {
            $row = $targets->profileSettings($userId, $profile);
            $profiles[$profile->value] = [
                'enabled' => (bool) ($row['enabled'] ?? true),
                'allowed_family_keys' => array_values(array_filter(
                    array_map('strval', (array) ($row['allowed_family_keys'] ?? [])),
                )),
            ];
        }

        $connections = [];
        foreach (ApiConnection::query()
            ->where(function ($query) use ($userId): void {
                $query->where('user_id', $userId)->orWhere('is_global', true);
            })->get() as $connection) {
            if (ApiConnectionProviders::isExternal((string) $connection->provider)
                || ApiConnectionProviders::isSeo((string) $connection->provider)) {
                continue;
            }
            $rawMeta = is_array($connection->metadata) ? $connection->metadata : [];
            $meta = self::sanitizeConnectionMetadata($rawMeta);
            $template = $meta['provider_template'] ?? ($rawMeta['provider_template'] ?? null);
            if (is_array($template)) {
                unset(
                    $template['api_key'],
                    $template['token'],
                    $template['password'],
                    $template['secret']
                );
            } else {
                $template = null;
            }

            $connections[] = [
                'connection_ref' => [
                    'provider_key' => (string) $connection->provider,
                    'connection_key' => $this->connectionKey($connection),
                ],
                'name' => (string) $connection->name,
                'is_global' => (bool) $connection->is_global,
                'status' => (string) ($connection->status ?? 'active'),
                'credential' => [
                    'configured' => filled($connection->api_key),
                    'exported' => false,
                ],
                'provider_template' => $template,
                'metadata' => $meta,
            ];
        }

        $preferred = $this->resolvePreferredProvider($userId, $connections);

        return [
            'preferred_provider' => $preferred,
            'usage_mode' => $settings->getDefaultAiUsageMode(),
            'resilience' => $resilience->get($userId),
            'routing' => $profiles,
            'general_image_families' => $adapter->familiesFromSlugs(
                $this->slugList($settings->getSettings()[SeoCreateArticleSettingsService::KEY_IMAGE_MODEL_PRIORITY] ?? []),
            ),
            'typography_image_families' => $adapter->familiesFromSlugs(
                $this->slugList($settings->getSettings()[SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_MODEL_PRIORITY] ?? []),
            ),
            'connections' => $connections,
        ];
    }

    public function diff(int $userId, array $incoming): array
    {
        $current = $this->export($userId);
        $lines = [];
        $warnings = [];
        $changed = 0;
        $unchanged = 0;

        $mode = AiUsageMode::tryFromMixed($incoming['usage_mode'] ?? null)?->value;
        if ($mode !== null) {
            if ($mode === ($current['usage_mode'] ?? null)) {
                $unchanged++;
            } else {
                $changed++;
                $lines[] = 'Global strategy: '.($current['usage_mode'] ?? '').' → '.$mode;
            }
        }

        $preferred = trim((string) ($incoming['preferred_provider'] ?? ''));
        if ($preferred !== '') {
            if ($preferred === ($current['preferred_provider'] ?? '')) {
                $unchanged++;
            } else {
                $changed++;
                $lines[] = 'Preferred provider: '.($current['preferred_provider'] ?? 'default').' → '.$preferred;
            }
        }

        if (isset($incoming['resilience']) && is_array($incoming['resilience'])) {
            $curResilience = is_array($current['resilience'] ?? null) ? $current['resilience'] : [];
            $newAi = $incoming['resilience']['max_ai_attempts'] ?? null;
            $newFree = $incoming['resilience']['max_free_attempts'] ?? null;
            if ($newAi !== null && (int) $newAi !== (int) ($curResilience['max_ai_attempts'] ?? null)) {
                $changed++;
                $lines[] = 'Resilience max_ai_attempts: '.($curResilience['max_ai_attempts'] ?? 'default').' → '.$newAi;
            } else {
                $unchanged++;
            }
            if ($newFree !== null && (int) $newFree !== (int) ($curResilience['max_free_attempts'] ?? null)) {
                $changed++;
                $lines[] = 'Resilience max_free_attempts: '.($curResilience['max_free_attempts'] ?? 'default').' → '.$newFree;
            } else {
                $unchanged++;
            }
        }

        $incomingRouting = is_array($incoming['routing'] ?? null) ? $incoming['routing'] : [];
        $catalog = new AiModelFamilyCatalog();
        foreach ($incomingRouting as $profileKey => $row) {
            if (! is_array($row)) {
                continue;
            }
            $before = $current['routing'][$profileKey]['allowed_family_keys'] ?? [];
            $after = array_values(array_filter(array_map('strval', (array) ($row['allowed_family_keys'] ?? []))));
            foreach ($after as $family) {
                if ($family !== AiModelFamilyCatalog::AUTOMATIC && $catalog->find($family) === null) {
                    $warnings[] = 'Missing model family: '.$family;
                }
            }
            if (json_encode($before) === json_encode($after)) {
                $unchanged++;
            } else {
                $changed++;
                $lines[] = 'Routing '.$profileKey.' updated';
            }
        }

        foreach ((array) ($incoming['connections'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $ref = is_array($row['connection_ref'] ?? null) ? $row['connection_ref'] : [];
            $provider = (string) ($ref['provider_key'] ?? '');
            $name = (string) ($row['name'] ?? $provider);
            $match = $this->findConnection($userId, $provider, (string) ($ref['connection_key'] ?? ''));

            if ($match === null) {
                $changed++;
                $lines[] = 'Create connection skeleton: '.$provider.' ('.$name.') (credential required)';
                $warnings[] = 'Connection '.$provider.' requires API credentials to be configured after import.';
            } elseif (! filled($match->api_key)) {
                $unchanged++;
                $warnings[] = $provider.' connection exists but credential cannot be imported.';
            } else {
                $unchanged++;
                $lines[] = 'Connection '.$provider.' exists (retaining existing credential).';
            }
        }

        return [
            'changed' => $changed,
            'unchanged' => $unchanged,
            'lines' => $lines,
            'warnings' => $warnings,
            'payload' => $incoming,
        ];
    }

    public function apply(int $userId, array $incoming, string $mode): void
    {
        unset($mode);
        $settings = app(SeoCreateArticleSettingsService::class);
        $targets = app(AiRoutingTargetService::class);
        $adapter = new ImageFamilySelectionAdapter();
        $patch = [];
        $usage = AiUsageMode::tryFromMixed($incoming['usage_mode'] ?? null);
        if ($usage !== null) {
            $patch[SeoCreateArticleSettingsService::KEY_DEFAULT_AI_USAGE_MODE] = $usage->value;
        }
        $current = $settings->getSettings();
        if (isset($incoming['general_image_families']) && is_array($incoming['general_image_families'])) {
            $patch[SeoCreateArticleSettingsService::KEY_IMAGE_MODEL_PRIORITY] = $adapter->expandPreservingOrder(
                array_map('strval', $incoming['general_image_families']),
                $this->slugList($current[SeoCreateArticleSettingsService::KEY_IMAGE_MODEL_PRIORITY] ?? []),
            );
        }
        if (isset($incoming['typography_image_families']) && is_array($incoming['typography_image_families'])) {
            $patch[SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_MODEL_PRIORITY] = $adapter->expandPreservingOrder(
                array_map('strval', $incoming['typography_image_families']),
                $this->slugList($current[SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_MODEL_PRIORITY] ?? []),
            );
        }

        $preferred = trim((string) ($incoming['preferred_provider'] ?? ''));
        if ($preferred !== '') {
            $this->setPreferredProvider($preferred);
        }

        if (isset($incoming['resilience']) && is_array($incoming['resilience'])) {
            try {
                app(AiResilienceSettingsService::class)->save($userId, [
                    'max_ai_attempts' => (int) ($incoming['resilience']['max_ai_attempts'] ?? AiResilienceSettingsService::DEFAULT_MAX_AI_ATTEMPTS),
                    'max_free_attempts' => (int) ($incoming['resilience']['max_free_attempts'] ?? AiResilienceSettingsService::DEFAULT_MAX_FREE_ATTEMPTS),
                ]);
            } catch (\InvalidArgumentException) {
                // Ignore invalid values gracefully
            }
        }

        if ($patch !== []) {
            $settings->saveSettings($patch);
        }

        $routing = is_array($incoming['routing'] ?? null) ? $incoming['routing'] : [];
        foreach (AiExecutionProfile::cases() as $profile) {
            $row = is_array($routing[$profile->value] ?? null) ? $routing[$profile->value] : null;
            if ($row === null) {
                continue;
            }
            $families = array_values(array_filter(array_map('strval', (array) ($row['allowed_family_keys'] ?? []))));
            $targets->saveSimplifiedSelection(
                $userId,
                $profile,
                $families !== [] ? $families : [AiModelFamilyCatalog::AUTOMATIC],
                $usage ?? AiUsageMode::Economy,
                (bool) ($row['enabled'] ?? true),
                ! $profile->isMedia(),
            );
        }

        $this->restoreConnections($userId, (array) ($incoming['connections'] ?? []));
    }

    /**
     * @param  list<mixed>  $connections
     */
    public function restoreConnections(int $userId, array $connections): void
    {
        foreach ($connections as $row) {
            if (! is_array($row)) {
                continue;
            }
            $ref = is_array($row['connection_ref'] ?? null) ? $row['connection_ref'] : [];
            $provider = strtolower(trim((string) ($ref['provider_key'] ?? '')));
            if ($provider === '') {
                continue;
            }
            $connKey = (string) ($ref['connection_key'] ?? '');
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $name = ucfirst($provider).' Connection';
            }
            $isGlobal = (bool) ($row['is_global'] ?? false);
            $template = is_array($row['provider_template'] ?? null) ? $row['provider_template'] : null;
            $rawMeta = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
            $meta = self::sanitizeConnectionMetadata($rawMeta);
            if ($template !== null) {
                unset(
                    $template['api_key'],
                    $template['token'],
                    $template['password'],
                    $template['secret']
                );
                $meta['provider_template'] = $template;
            }

            $existing = $this->findConnection($userId, $provider, $connKey);
            if ($existing instanceof ApiConnection) {
                // NEVER erase or overwrite existing secrets
                $currentMeta = is_array($existing->metadata) ? $existing->metadata : [];
                $existing->metadata = array_merge($currentMeta, $meta);
                $existing->save();
                continue;
            }

            // Create connection skeleton without secret, disabled until configured
            $connection = new ApiConnection();
            $connection->user_id = $userId;
            $connection->provider = $provider;
            $connection->name = $name;
            $connection->api_key = null;
            $connection->status = 'inactive';
            $connection->is_global = $isGlobal;
            $connection->metadata = $meta !== [] ? $meta : null;
            $connection->save();
        }
    }

    private function connectionKey(ApiConnection $connection): string
    {
        $slug = strtolower(trim((string) $connection->name));
        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?? $slug;

        return $slug !== '' ? $slug : (string) $connection->provider;
    }

    private function findConnection(int $userId, string $provider, string $connectionKey): ?ApiConnection
    {
        $rows = ApiConnection::query()
            ->where('provider', $provider)
            ->where(function ($query) use ($userId): void {
                $query->where('user_id', $userId)->orWhere('is_global', true);
            })
            ->get();

        foreach ($rows as $row) {
            if ($this->connectionKey($row) === $connectionKey) {
                return $row;
            }
        }

        if ($rows->count() === 1) {
            return $rows->first();
        }

        return null;
    }

    public function getPreferredProvider(): string
    {
        return (string) \App\Models\WpOption::get('seo_ai_preferred_provider', 'openrouter');
    }

    public function setPreferredProvider(string $provider): void
    {
        \App\Models\WpOption::set('seo_ai_preferred_provider', $provider);
    }

    /**
     * @param  list<array<string, mixed>>  $connections
     */
    private function resolvePreferredProvider(int $userId, array $connections): string
    {
        unset($userId, $connections);

        return $this->getPreferredProvider();
    }

    /**
     * @return list<string>
     */
    private function slugList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) || is_int($item)) {
                $out[] = (string) $item;
                continue;
            }
            if (is_array($item) && isset($item['slug'])) {
                $out[] = (string) $item['slug'];
            }
        }

        return $out;
    }
}
