<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Extension;

use App\Addons\SeoContentAi\SeoContentAiServiceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ExtensionStateStore
{
    private const CACHE_PREFIX = 'seo_extension_state:';

    public function isEnabled(string $id): bool
    {
        if ($this->hasDbTable()) {
            $row = DB::connection(SeoContentAiServiceProvider::DB_CONNECTION)
                ->table('seo_extension_states')
                ->where('extension_id', $id)
                ->first();

            if ($row !== null) {
                return (bool) $row->enabled;
            }
        }

        $cached = Cache::get(self::CACHE_PREFIX.'enabled:'.$id);

        return $cached !== null ? (bool) $cached : true;
    }

    public function setEnabled(string $id, bool $enabled): void
    {
        Cache::forever(self::CACHE_PREFIX.'enabled:'.$id, $enabled);

        if (! $this->hasDbTable()) {
            return;
        }

        try {
            DB::connection(SeoContentAiServiceProvider::DB_CONNECTION)
                ->table('seo_extension_states')
                ->updateOrInsert(
                    ['extension_id' => $id],
                    [
                        'enabled' => $enabled,
                        'status' => $enabled ? 'healthy' : 'disabled',
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
        } catch (Throwable) {
            // cache vẫn đủ cho runtime
        }
    }

    public function getStatus(string $id): string
    {
        if ($this->hasDbTable()) {
            $row = DB::connection(SeoContentAiServiceProvider::DB_CONNECTION)
                ->table('seo_extension_states')
                ->where('extension_id', $id)
                ->first();

            if ($row !== null && is_string($row->status)) {
                return $row->status;
            }
        }

        $cached = Cache::get(self::CACHE_PREFIX.'status:'.$id);

        return is_string($cached) ? $cached : 'healthy';
    }

    /**
     * @param  array<string, mixed>  $health
     */
    public function setHealth(string $id, array $health): void
    {
        $status = (string) ($health['status'] ?? (($health['ok'] ?? true) ? 'healthy' : 'error'));
        Cache::forever(self::CACHE_PREFIX.'status:'.$id, $status);
        Cache::forever(self::CACHE_PREFIX.'health:'.$id, $health);

        if (! $this->hasDbTable()) {
            return;
        }

        try {
            DB::connection(SeoContentAiServiceProvider::DB_CONNECTION)
                ->table('seo_extension_states')
                ->updateOrInsert(
                    ['extension_id' => $id],
                    [
                        'status' => $status,
                        'health_payload' => json_encode($health, JSON_THROW_ON_ERROR),
                        'last_error' => isset($health['message']) && ! ($health['ok'] ?? true)
                            ? (string) $health['message']
                            : null,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
        } catch (Throwable) {
            // ignore
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getHealthPayload(string $id): ?array
    {
        if ($this->hasDbTable()) {
            $row = DB::connection(SeoContentAiServiceProvider::DB_CONNECTION)
                ->table('seo_extension_states')
                ->where('extension_id', $id)
                ->first();

            if ($row !== null && $row->health_payload !== null) {
                $decoded = json_decode((string) $row->health_payload, true);

                return is_array($decoded) ? $decoded : null;
            }
        }

        $cached = Cache::get(self::CACHE_PREFIX.'health:'.$id);

        return is_array($cached) ? $cached : null;
    }

    private function hasDbTable(): bool
    {
        try {
            return Schema::connection(SeoContentAiServiceProvider::DB_CONNECTION)
                ->hasTable('seo_extension_states');
        } catch (Throwable) {
            return false;
        }
    }
}
