<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\SettingsTransfer;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\Services\AiModelFamilyCatalog;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\ImageFamilySelectionAdapter;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiUsageMode;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;

final class AiCenterSettingsSection implements PortableSettingsSection
{
    public function key(): string
    {
        return 'ai';
    }

    public function export(int $userId): array
    {
        $settings = app(SeoCreateArticleSettingsService::class);
        $targets = app(AiRoutingTargetService::class);
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
            $meta = is_array($connection->metadata) ? $connection->metadata : [];
            $template = $meta['provider_template'] ?? null;
            $connections[] = [
                'connection_ref' => [
                    'provider_key' => (string) $connection->provider,
                    'connection_key' => $this->connectionKey($connection),
                ],
                'name' => (string) $connection->name,
                'credential' => [
                    'configured' => filled($connection->api_key),
                    'exported' => false,
                ],
                'provider_template' => is_array($template) ? $template : null,
            ];
        }

        return [
            'usage_mode' => $settings->getDefaultAiUsageMode(),
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
            $match = $this->findConnection($userId, $provider, (string) ($ref['connection_key'] ?? ''));
            if ($match === null) {
                $warnings[] = 'Connection missing: '.$provider;
            } elseif (! filled($match->api_key)) {
                $warnings[] = $provider.' connection exists but credential cannot be imported.';
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

        return $rows->first();
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
