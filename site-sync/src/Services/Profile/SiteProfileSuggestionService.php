<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Profile;

use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;
use App\Models\Site;

/**
 * Profile suggestions — never overwrite manual fields.
 */
final class SiteProfileSuggestionService
{
    /**
     * @return array{suggestions: list<array<string, mixed>>, applied: list<string>}
     */
    public function syncFromProfile(Site $site, array $profile, bool $applyBlank = true): array
    {
        $store = SiteSyncSiteMeta::getJson($site, SiteSyncSchema::META_PROFILE_SUGGESTIONS) ?? [
            'items' => [],
            'rejected' => [],
        ];
        $suggestions = [];
        $applied = [];
        $map = [
            'site_name' => (string) ($profile['name'] ?? $profile['site_name'] ?? ''),
            'short_description' => (string) ($profile['tagline'] ?? $profile['short_description'] ?? ''),
            'phone' => (string) ($profile['phone'] ?? ''),
            'email' => (string) ($profile['email'] ?? ''),
            'organization_name' => (string) ($profile['organization'] ?? $profile['organization_name'] ?? ''),
        ];

        foreach ($map as $field => $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            $hash = hash('sha256', $field.'|'.$value);
            if (in_array($hash, $store['rejected'] ?? [], true)) {
                continue;
            }
            $suggestions[] = [
                'field' => $field,
                'value' => $value,
                'hash' => $hash,
                'source' => 'wordpress_profile',
                'confidence' => 0.7,
            ];
        }

        $store['items'] = $suggestions;
        SiteSyncSiteMeta::putJson($site, SiteSyncSchema::META_PROFILE_SUGGESTIONS, $store);

        return ['suggestions' => $suggestions, 'applied' => $applied];
    }

    public function accept(Site $site, string $hash): array
    {
        $store = SiteSyncSiteMeta::getJson($site, SiteSyncSchema::META_PROFILE_SUGGESTIONS) ?? ['items' => [], 'rejected' => []];
        $item = null;
        foreach ($store['items'] as $row) {
            if (($row['hash'] ?? '') === $hash) {
                $item = $row;
                break;
            }
        }
        if ($item === null) {
            return ['success' => false, 'message' => 'Suggestion not found'];
        }
        $store['accepted'][] = $item;
        $store['items'] = array_values(array_filter(
            $store['items'],
            static fn (array $r): bool => ($r['hash'] ?? '') !== $hash,
        ));
        SiteSyncSiteMeta::putJson($site, SiteSyncSchema::META_PROFILE_SUGGESTIONS, $store);

        return ['success' => true, 'message' => 'Accepted', 'item' => $item];
    }

    public function reject(Site $site, string $hash): array
    {
        $store = SiteSyncSiteMeta::getJson($site, SiteSyncSchema::META_PROFILE_SUGGESTIONS) ?? ['items' => [], 'rejected' => []];
        $store['rejected'] = array_values(array_unique([...($store['rejected'] ?? []), $hash]));
        $store['items'] = array_values(array_filter(
            $store['items'],
            static fn (array $r): bool => ($r['hash'] ?? '') !== $hash,
        ));
        SiteSyncSiteMeta::putJson($site, SiteSyncSchema::META_PROFILE_SUGGESTIONS, $store);

        return ['success' => true, 'message' => 'Rejected until source hash changes'];
    }
}
