<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Projects;

use App\Models\Site;
use Illuminate\Support\Facades\Schema;

final class EloquentSiteDirectory implements SiteDirectory
{
    public function listActiveSites(): array
    {
        if (! Schema::hasTable('sites')) {
            return [];
        }

        $query = Site::query()->orderBy('domain');
        if (Schema::hasColumn('sites', 'status')) {
            $query->where('status', 'active');
        }

        $out = [];
        foreach ($query->limit(500)->get(['id', 'domain']) as $site) {
            if (! $site instanceof Site) {
                continue;
            }
            $id = (int) $site->getKey();
            $domain = strtolower(trim((string) $site->domain));
            if ($id <= 0 || $domain === '' || str_contains($domain, '__trashed__')) {
                continue;
            }
            $out[] = ['id' => $id, 'domain' => $domain];
        }

        return $out;
    }
}
