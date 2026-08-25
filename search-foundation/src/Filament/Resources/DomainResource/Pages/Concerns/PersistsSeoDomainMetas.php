<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages\Concerns;

use App\Models\Site;
use Omnichannel\Addons\WordPress\Services\SitePrimaryLanguageService;
use Illuminate\Support\Str;

trait PersistsSeoDomainMetas
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function fillSeoMetaFormData(Site $site, array $data): array
    {
        $site->loadMissing('metas');

        $data['seo_platform'] = $site->getMeta('seo_platform') ?? 'wordpress';
        $data['seo_domain_type'] = $site->getMeta('seo_domain_type') ?? 'news';
        $data['seo_read_token'] = $site->getMeta('seo_read_token') ?? '';
        $data['seo_migration_token'] = $site->getMeta('seo_migration_token') ?? '';
        $data['seo_primary_language'] = app(SitePrimaryLanguageService::class)->resolvePrimaryLanguage($site);

        if (($data['seo_platform'] ?? '') === 'wordpress') {
            if ($data['seo_read_token'] === '' || $data['seo_read_token'] === null) {
                $data['seo_read_token'] = Str::random(60);
                $site->metas()->updateOrCreate(
                    ['meta_key' => 'seo_read_token'],
                    ['meta_value' => $data['seo_read_token']],
                );
            }
            if ($data['seo_migration_token'] === '' || $data['seo_migration_token'] === null) {
                $data['seo_migration_token'] = Str::random(60);
                $site->metas()->updateOrCreate(
                    ['meta_key' => 'seo_migration_token'],
                    ['meta_value' => $data['seo_migration_token']],
                );
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>  Site attributes only (seo_* keys removed)
     */
    protected function persistSeoMetaFormData(Site $site, array $data): array
    {
        $platform = isset($data['seo_platform']) ? (string) $data['seo_platform'] : 'custom';
        $domainType = isset($data['seo_domain_type']) ? (string) $data['seo_domain_type'] : 'news';
        $readToken = isset($data['seo_read_token']) ? (string) $data['seo_read_token'] : '';
        $migrationToken = isset($data['seo_migration_token']) ? (string) $data['seo_migration_token'] : '';
        $primaryLanguage = array_key_exists('seo_primary_language', $data)
            ? $data['seo_primary_language']
            : null;

        if ($platform === 'wordpress') {
            if ($readToken === '') {
                $readToken = Str::random(60);
            }
            if ($migrationToken === '') {
                $migrationToken = Str::random(60);
            }
        }

        unset(
            $data['seo_platform'],
            $data['seo_domain_type'],
            $data['seo_read_token'],
            $data['seo_migration_token'],
            $data['seo_primary_language'],
        );

        $site->metas()->updateOrCreate(
            ['meta_key' => 'seo_platform'],
            ['meta_value' => $platform],
        );
        $publisherKey = $platform === 'wordpress' ? 'wordpress' : (
            isset($data['seo_publisher_key']) && is_string($data['seo_publisher_key']) && trim($data['seo_publisher_key']) !== ''
                ? strtolower(trim($data['seo_publisher_key']))
                : ''
        );
        if ($publisherKey !== '') {
            $site->metas()->updateOrCreate(
                ['meta_key' => 'seo_publisher_key'],
                ['meta_value' => $publisherKey],
            );
        }
        $site->metas()->updateOrCreate(
            ['meta_key' => 'seo_domain_type'],
            ['meta_value' => $domainType],
        );

        if ($platform === 'wordpress') {
            $site->metas()->updateOrCreate(
                ['meta_key' => 'seo_read_token'],
                ['meta_value' => $readToken],
            );
            $site->metas()->updateOrCreate(
                ['meta_key' => 'seo_migration_token'],
                ['meta_value' => $migrationToken],
            );
        } else {
            $site->metas()->updateOrCreate(
                ['meta_key' => 'seo_read_token'],
                ['meta_value' => ''],
            );
            $site->metas()->updateOrCreate(
                ['meta_key' => 'seo_migration_token'],
                ['meta_value' => ''],
            );
        }

        $primaryCode = is_string($primaryLanguage) ? trim($primaryLanguage) : '';
        $primarySvc = app(SitePrimaryLanguageService::class);
        if ($primarySvc->hasPolylang($site)) {
            $primarySvc->setPrimaryLanguage(
                $site,
                $primaryCode !== '' ? $primaryCode : null,
            );
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function defaultSeoMetaForCreateForm(array $data): array
    {
        $data['seo_platform'] = $data['seo_platform'] ?? 'wordpress';
        $data['seo_domain_type'] = $data['seo_domain_type'] ?? 'news';

        if (($data['seo_platform'] ?? '') === 'wordpress') {
            $data['seo_read_token'] = $data['seo_read_token'] ?? Str::random(60);
            $data['seo_migration_token'] = $data['seo_migration_token'] ?? Str::random(60);
        } else {
            $data['seo_read_token'] = '';
            $data['seo_migration_token'] = '';
        }

        return $data;
    }
}
