<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\ConfigurationSyncCoordinator;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\ConfigurationSyncException;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\GoldenBootstrapService;

final class SyncGoldenConfigurationCommand extends Command
{
    protected $signature = 'seo:config-sync {--user=1 : Workspace user id} {--bootstrap : Import Golden only when the Client is genuinely unconfigured}';

    protected $description = 'Report or upload sanitized SEO configuration changes. Disabled on the scheduler until live integration passes.';

    public function handle(ConfigurationSyncCoordinator $coordinator, GoldenBootstrapService $bootstrap): int
    {
        $userId = (int) $this->option('user');
        try {
            if ((bool) $this->option('bootstrap')) {
                $result = $bootstrap->bootstrap($userId);
            } else {
                $result = $coordinator->sync($userId);
            }
        } catch (ConfigurationSyncException $e) {
            $this->error($e->errorCode);

            return self::FAILURE;
        }

        $safe = $result;
        unset($safe['body'], $safe['ack']);
        $this->line((string) json_encode([
            'status' => $safe['status'] ?? 'unknown',
            'uploaded' => $safe['uploaded'] ?? false,
            'error_code' => $safe['error_code'] ?? null,
        ], JSON_UNESCAPED_SLASHES));

        return ($safe['status'] ?? '') === 'deferred' ? self::FAILURE : self::SUCCESS;
    }
}
