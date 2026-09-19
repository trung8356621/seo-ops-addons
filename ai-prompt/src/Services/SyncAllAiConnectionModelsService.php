<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\Support\AiConnectionCredential;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;

/**
 * Sync all configured AI connections' provider catalogs, then auto-map known recommended models.
 * One provider failure does not abort others. Never invokes paid language-curation LLM.
 * Does not auto-seed arbitrary area membership via connection coverage reconcile.
 *
 * @phpstan-type SyncRow array{
 *   connection_id: int,
 *   provider: string,
 *   name: string,
 *   ok: bool,
 *   skipped: bool,
 *   message: string
 * }
 */
final class SyncAllAiConnectionModelsService
{
    /**
     * @return array{
     *   ok: int,
     *   failed: int,
     *   skipped: int,
     *   coverage_added: int,
     *   auto_mapped: int,
     *   rows: list<SyncRow>,
     *   summary_lines: list<string>
     * }
     */
    public function run(int $userId): array
    {
        $inventory = app(AiConnectionInventoryService::class);

        $rows = [];
        $ok = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($inventory->configuredAiConnections($userId) as $connection) {
            if (! $connection instanceof ApiConnection) {
                continue;
            }
            if ((string) $connection->status === 'inactive') {
                $skipped++;
                $rows[] = $this->row($connection, false, true, 'inactive');
                continue;
            }
            if (! AiConnectionCredential::isUsable($connection->api_key)) {
                $failed++;
                $rows[] = $this->row($connection, false, false, 'missing_credentials');
                continue;
            }
            if (! ApiConnectionProviders::isAi((string) $connection->provider)) {
                $skipped++;
                $rows[] = $this->row($connection, false, true, 'not_ai_provider');
                continue;
            }

            try {
                $freshness = app(AiModelCatalogFreshnessService::class);
                $result = $freshness->requestRefresh(
                    $connection,
                    $userId,
                    forced: true,
                    blocking: true,
                    respectForcedDebounce: false,
                );
                $synced = (bool) ($result['ok'] ?? false)
                    || (($result['reason'] ?? '') === 'already_fresh');
                if ($synced) {
                    $ok++;
                    $rows[] = $this->row($connection, true, false, 'synchronized');
                } else {
                    $failed++;
                    $rows[] = $this->row(
                        $connection,
                        false,
                        (bool) ($result['skipped'] ?? false),
                        (string) ($result['reason'] ?? 'sync_failed'),
                    );
                }
            } catch (\Throwable $e) {
                $failed++;
                $rows[] = $this->row($connection, false, false, 'sync_failed');
                logger()->warning('AI Sync All provider failed', [
                    'connection_id' => (int) $connection->id,
                    'provider' => (string) $connection->provider,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            app(AiModelPrimaryTypeClassifier::class)->classifyForUser($userId);
        } catch (\Throwable) {
        }

        $autoMapped = 0;
        if ($ok > 0) {
            try {
                $mapResult = app(AiRecommendedModelMapper::class)->mapForUser($userId);
                $autoMapped = $mapResult->enabled;
            } catch (\Throwable $e) {
                logger()->warning('AI Sync All recommended auto-map failed', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $freePool = app(OpenRouterFreePoolService::class);
            $freePool->ensureRouterAnchors($userId);
            foreach ($inventory->configuredAiConnections($userId) as $connection) {
                if ($connection instanceof ApiConnection
                    && (string) $connection->provider === ApiConnectionProviders::OPENROUTER) {
                    $freePool->refreshCatalogSnapshot($connection);
                }
            }
        } catch (\Throwable $e) {
            logger()->warning('AI Sync All free pool refresh failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }

        $inventory->forgetCache();
        try {
            app(AiModelPriorityService::class)->forgetMemo();
        } catch (\Throwable) {
        }

        $summary = [];
        foreach ($rows as $row) {
            $mark = $row['ok'] ? '✓' : ($row['skipped'] ? '·' : '⚠');
            $summary[] = trim($row['name'].' '.$mark.' '.$row['message']);
        }
        $summary[] = 'Models ✓ recommended auto-map (+'.$autoMapped.')';

        return [
            'ok' => $ok,
            'failed' => $failed,
            'skipped' => $skipped,
            // Retained for callers; coverage auto-seed no longer runs on sync.
            'coverage_added' => 0,
            'auto_mapped' => $autoMapped,
            'rows' => $rows,
            'summary_lines' => $summary,
        ];
    }

    /**
     * @return SyncRow
     */
    private function row(ApiConnection $connection, bool $ok, bool $skipped, string $message): array
    {
        return [
            'connection_id' => (int) $connection->id,
            'provider' => (string) $connection->provider,
            'name' => (string) $connection->name !== ''
                ? (string) $connection->name
                : ApiConnectionProviders::label((string) $connection->provider),
            'ok' => $ok,
            'skipped' => $skipped,
            'message' => $message,
        ];
    }
}
