<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\Support\AiConnectionCredential;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;

/**
 * Sync all configured AI connections' provider catalogs, then reconcile routing coverage.
 * One provider failure does not abort others. Never invokes paid language-curation LLM.
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
     *   rows: list<SyncRow>,
     *   summary_lines: list<string>
     * }
     */
    public function run(int $userId): array
    {
        $router = app(AiModelRouterService::class);
        $inventory = app(AiConnectionInventoryService::class);
        $coverage = app(AiConnectionCoverageService::class);

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
                $synced = $router->syncModelsForConnection((int) $connection->id);
                if ($synced) {
                    $ok++;
                    $rows[] = $this->row($connection, true, false, 'synchronized');
                } else {
                    $failed++;
                    $rows[] = $this->row($connection, false, false, 'sync_failed');
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

        $coverageAdded = 0;
        try {
            $coverageAdded = $coverage->reconcileAllAreas($userId);
        } catch (\Throwable $e) {
            logger()->warning('AI Sync All coverage reconcile failed', [
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
        $summary[] = 'Routing ✓ coverage updated (+'.$coverageAdded.')';

        return [
            'ok' => $ok,
            'failed' => $failed,
            'skipped' => $skipped,
            'coverage_added' => $coverageAdded,
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
