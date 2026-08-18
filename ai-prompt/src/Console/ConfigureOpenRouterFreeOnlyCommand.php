<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Console;

use App\Models\ApiConnection;
use Illuminate\Console\Command;
use Omnichannel\Addons\AiPrompt\Services\AiFreeOnlyRoutingConfigurator;
use Omnichannel\Addons\AiPrompt\Services\AiModelPrimaryTypeClassifier;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;

/**
 * Sync OpenRouter models, classify text primary types, enable FREE chat models in inventory,
 * and restore global Routing (FREE is run-level policy, not global order).
 *
 * php artisan seo:ai:configure-openrouter-free-only
 * php artisan seo:ai:configure-openrouter-free-only --user=2
 */
final class ConfigureOpenRouterFreeOnlyCommand extends Command
{
    protected $signature = 'seo:ai:configure-openrouter-free-only
        {--user= : Limit to a single user id}
        {--restore-only : Only strip auto-merged FREE keys from global Routing}';

    protected $description = 'Sync OpenRouter FREE inventory and restore global routing (no free-first)';

    public function handle(
        AiModelRouterService $router,
        AiModelPrimaryTypeClassifier $classifier,
        AiFreeOnlyRoutingConfigurator $freeOnly,
    ): int {
        $userOption = $this->option('user');
        $userId = $userOption !== null && $userOption !== '' ? (int) $userOption : null;
        $restoreOnly = (bool) $this->option('restore-only');

        if ($restoreOnly) {
            $ownerIds = [];
            if ($userId !== null) {
                $ownerIds[$userId] = true;
            } else {
                foreach (\Omnichannel\Addons\AiPrompt\Models\AiRoutingProfile::query()->distinct()->pluck('user_id') as $id) {
                    $ownerIds[(int) $id] = true;
                }
            }
            $stripped = 0;
            foreach (array_keys($ownerIds) as $ownerId) {
                $restore = $freeOnly->restoreGlobalRouting((int) $ownerId);
                foreach ($restore['stripped_keys'] as $keys) {
                    $stripped += count($keys);
                }
            }
            $this->info(sprintf('restore-only users=%d routing_keys_stripped=%d', count($ownerIds), $stripped));

            return self::SUCCESS;
        }

        $query = ApiConnection::query()
            ->where('provider', ApiConnectionProviders::OPENROUTER)
            ->where('status', 'active')
            ->where(function ($inner): void {
                $inner->whereNotNull('api_key')->where('api_key', '!=', '');
            });
        if ($userId !== null) {
            $query->where(function ($inner) use ($userId): void {
                $inner->where('user_id', $userId)->orWhere('is_global', true);
            });
        }

        $synced = 0;
        $failed = 0;
        $ownerIds = [];
        foreach ($query->orderBy('id')->get() as $connection) {
            $ok = $router->syncOpenAiCompatibleModels((int) $connection->id);
            if ($ok) {
                $synced++;
            } else {
                $failed++;
                $this->warn('sync failed connection='.(int) $connection->id);
            }
            $ownerIds[(int) $connection->user_id] = true;
            if ((bool) $connection->is_global) {
                if ($userId !== null) {
                    $ownerIds[$userId] = true;
                }
            }
        }

        $classified = ['classified' => 0, 'free_enabled' => 0, 'skipped_manual' => 0, 'excluded' => 0];
        $stripped = 0;
        foreach (array_keys($ownerIds) as $ownerId) {
            $row = $classifier->classifyForUser((int) $ownerId);
            foreach ($classified as $key => $value) {
                $classified[$key] = $value + $row[$key];
            }
            $restore = $freeOnly->restoreGlobalRouting((int) $ownerId);
            foreach ($restore['stripped_keys'] as $keys) {
                $stripped += count($keys);
            }
        }

        $this->info(sprintf(
            'synced=%d failed=%d classified=%d free_enabled=%d skipped_manual=%d excluded=%d routing_keys_stripped=%d',
            $synced,
            $failed,
            $classified['classified'],
            $classified['free_enabled'],
            $classified['skipped_manual'],
            $classified['excluded'],
            $stripped,
        ));

        return $failed > 0 && $synced === 0 ? self::FAILURE : self::SUCCESS;
    }
}
