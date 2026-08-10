<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Support;

use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Fail soft when Site Sync V2 migrations chưa chạy trên omi_seo_ai.
 */
final class SiteSyncInfrastructure
{
    private const REQUIRED_TABLES = [
        'seo_site_sync_runs',
        'seo_site_sync_run_steps',
        'seo_site_capabilities',
    ];

    public static function tablesReady(): bool
    {
        try {
            $schema = Schema::connection('omi_seo_ai');
            foreach (self::REQUIRED_TABLES as $table) {
                if (! $schema->hasTable($table)) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function hasTable(string $table): bool
    {
        try {
            return Schema::connection('omi_seo_ai')->hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }
}
