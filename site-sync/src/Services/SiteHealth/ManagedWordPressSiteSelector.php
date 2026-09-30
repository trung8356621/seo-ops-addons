<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\SiteHealth;

use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;

final class ManagedWordPressSiteSelector
{
    /** @return Builder<Site> */
    public function query(): Builder
    {
        return Site::query()
            ->where('status', 'active')
            ->where(function (Builder $query): void {
                $query->whereHas('metas', static function (Builder $metas): void {
                    $metas->where('meta_key', 'seo_read_token')
                        ->whereNotNull('meta_value')
                        ->whereRaw("TRIM(meta_value) <> ''");
                })->orWhereHas('siteServices', static function (Builder $siteServices): void {
                    $siteServices->where('status', 'active')
                        ->whereHas('service', static fn (Builder $services): Builder => $services->where('slug', 'wp-headless'));
                });
            })
            ->orderBy('id');
    }
}
