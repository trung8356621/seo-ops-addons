<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Console;

use App\Models\ApiConnection;
use Illuminate\Console\Command;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiCenterModelPresenter;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\OpenAiCompatibleProtocolAdapter;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\AiPrompt\Support\DecisionModelIdentityCatalog;

/**
 * Prints OpenRouter Decision catalog ids. Never prints API keys.
 *
 * php artisan seo:ai:decision-catalog-diag
 * php artisan seo:ai:decision-catalog-diag --sync
 */
final class DecisionCatalogDiagCommand extends Command
{
    protected $signature = 'seo:ai:decision-catalog-diag {--sync : Run canonical OpenRouter model sync first}';

    protected $description = 'Show upstream, persisted, and available Decision model ids for OpenRouter connections';

    public function handle(
        OpenAiCompatibleProtocolAdapter $adapter,
        AiModelRouterService $router,
        AiCenterModelPresenter $presenter,
    ): int {
        $connections = ApiConnection::query()
            ->where('provider', ApiConnectionProviders::OPENROUTER)
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        foreach ($connections as $connection) {
            if (trim((string) $connection->api_key) === '') {
                $this->line('connection='.$connection->id.' skipped=no_key');
                continue;
            }
            if ((bool) $this->option('sync')) {
                $router->syncOpenAiCompatibleModels((int) $connection->id);
            }

            $upstream = [];
            try {
                foreach ($adapter->listModels($connection, ['output_modalities' => 'decisions']) as $row) {
                    $id = (string) ($row['id'] ?? '');
                    if (DecisionModelIdentityCatalog::isOpenRouterJev($id)) {
                        $upstream[] = $id;
                    }
                }
            } catch (\Throwable $exception) {
                $upstream[] = 'error';
            }

            $persisted = SeoAiModel::query()
                ->where('api_connection_id', $connection->id)
                ->orderBy('raw_model_name')
                ->pluck('raw_model_name')
                ->filter(static fn (mixed $raw): bool => DecisionModelIdentityCatalog::isOpenRouterJev((string) $raw))
                ->values()
                ->all();

            $available = [];
            $page = $presenter->availablePage((int) $connection->user_id, (int) $connection->id, [
                'area' => 'decision',
                'status' => 'available',
                'provider' => 'openrouter',
            ]);
            foreach ($page['rows'] as $row) {
                foreach ($row['releases'] ?? [] as $release) {
                    if (is_array($release)) {
                        $available[] = (string) ($release['raw'] ?? '');
                    }
                }
            }

            $this->line('connection='.$connection->id);
            $this->line('upstream Jev ids: '.json_encode(array_values($upstream)));
            $this->line('persisted Jev ids: '.json_encode(array_values($persisted)));
            $this->line('Decision available: '.json_encode(array_values($available)));
        }

        return self::SUCCESS;
    }
}
