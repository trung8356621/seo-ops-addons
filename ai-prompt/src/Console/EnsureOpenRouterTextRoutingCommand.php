<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\AiPrompt\Services\OpenRouterTextRoutingCatalog;

/**
 * Idempotent: ensure curated OpenRouter text models + Text Routing Custom pools.
 *
 * php artisan seo:ai:ensure-openrouter-text-routing
 * php artisan seo:ai:ensure-openrouter-text-routing --user=2
 */
final class EnsureOpenRouterTextRoutingCommand extends Command
{
    protected $signature = 'seo:ai:ensure-openrouter-text-routing {--user= : Limit to a single user id}';

    protected $description = 'Idempotent ensure OpenRouter text models in AI Center Models and Text Routing Custom pools';

    public function handle(OpenRouterTextRoutingCatalog $catalog): int
    {
        $userOption = $this->option('user');
        $userId = $userOption !== null && $userOption !== '' ? (int) $userOption : null;
        $result = $catalog->apply($userId);

        $this->info(sprintf(
            'connections=%d models_upserted=%d models_enabled=%d profiles_updated=%d',
            $result['connections'],
            $result['models_upserted'],
            $result['models_enabled'],
            $result['profiles_updated'],
        ));

        foreach ($result['execution_keys'] as $uid => $byProfile) {
            $this->line('user='.$uid);
            foreach ($byProfile as $profile => $keys) {
                $this->line('  '.$profile.': '.implode(', ', $keys));
            }
        }

        return self::SUCCESS;
    }
}
