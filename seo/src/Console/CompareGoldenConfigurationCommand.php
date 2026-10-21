<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\ConfigurationSyncCoordinator;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\ConfigurationSyncException;

final class CompareGoldenConfigurationCommand extends Command
{
    protected $signature = 'seo:config-compare {--user=1 : Workspace user id}';

    protected $description = 'Compare live SEO configuration with the local Golden baseline. Does not upload.';

    public function handle(ConfigurationSyncCoordinator $coordinator): int
    {
        try {
            $result = $coordinator->compare((int) $this->option('user'));
        } catch (ConfigurationSyncException $e) {
            $this->error($e->errorCode);

            return self::FAILURE;
        }
        $this->line((string) json_encode([
            'outcome' => $result['outcome'],
            'reason' => $result['reason'],
        ], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
