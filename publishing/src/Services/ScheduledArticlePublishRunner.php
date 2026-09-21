<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Services;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessEventName;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookEmitter;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueRunner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\PublishingConnectionCandidateResolver;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use App\Models\SeoDatabaseConnection;
use App\Support\RuntimeLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cron due-scheduled articles: emit business event only — never direct WordPress mutate.
 * Content Project items dùng scheduled_publish_at (SaaS queue), không WP future/cron.
 *
 * Connection bootstrap uses canonical ServiceDatabaseConnection via SeoDatabaseConnectionService.
 */
final class ScheduledArticlePublishRunner
{
    public function __construct(
        private readonly SeoDatabaseConnectionService $databaseConnection,
        private readonly BusinessHookEmitter $emitter,
        private readonly ContentProjectPublishingQueueRunner $contentProjectQueue,
        private readonly PublishingConnectionCandidateResolver $connectionCandidates,
    ) {}

    /**
     * @return array{
     *     processed: int,
     *     published: int,
     *     failed: int,
     *     skipped: int,
     *     bootstrap_failed: int,
     *     connections_attempted: int,
     *     connections_skipped: int
     * }
     */
    public function run(): array
    {
        $stats = [
            'processed' => 0,
            'published' => 0,
            'failed' => 0,
            'skipped' => 0,
            'bootstrap_failed' => 0,
            'connections_attempted' => 0,
            'connections_skipped' => 0,
        ];

        try {
            $canonical = $this->databaseConnection->resolveDefaultSharedConnectionRecord();
            if ($canonical instanceof SeoDatabaseConnection
                && $this->connectionCandidates->isEligible($canonical) === null
            ) {
                $stats['connections_attempted']++;
                $this->runForConnection($canonical, $stats);

                return $stats;
            }

            $this->databaseConnection->bootstrapLegacySharedConnection();
            $meta = [
                'connection_id' => null,
                'hash_id' => null,
                'connection_name' => $this->databaseConnection->connectionName(),
                'database' => (string) config('seo-content-ai.legacy_shared_database', 'omi_seo_ai'),
                'resolver' => 'SeoDatabaseConnectionService::bootstrapLegacySharedConnection',
                'runtime' => 'console',
            ];
            try {
                DB::connection($this->databaseConnection->connectionName())->getPdo();
            } catch (Throwable $e) {
                throw new \RuntimeException(
                    'Không kết nối được tới database SEO: '.$e->getMessage(),
                    previous: $e,
                );
            }
            $projectStats = $this->contentProjectQueue->dispatchDue($meta);
            $stats['processed'] += $projectStats['processed'];
            $stats['published'] += (int) ($projectStats['published_confirmed'] ?? $projectStats['published'] ?? 0);
            $stats['published_confirmed'] = ($stats['published_confirmed'] ?? 0) + (int) ($projectStats['published_confirmed'] ?? $projectStats['published'] ?? 0);
            $stats['claimed'] = ($stats['claimed'] ?? 0) + (int) ($projectStats['claimed'] ?? $projectStats['claimed_count'] ?? 0);
            $stats['dispatched'] = ($stats['dispatched'] ?? 0) + (int) ($projectStats['dispatched'] ?? $projectStats['dispatched_count'] ?? 0);
            $stats['publisher_started'] = ($stats['publisher_started'] ?? 0) + (int) ($projectStats['publisher_started'] ?? $projectStats['publisher_started_count'] ?? 0);
            $stats['retry_scheduled'] = ($stats['retry_scheduled'] ?? 0) + (int) ($projectStats['retry_scheduled'] ?? $projectStats['retry_wait_count'] ?? 0);
            $stats['failed'] += $projectStats['failed'];
            $stats['skipped'] += $projectStats['skipped'] ?? 0;
            $this->dispatchDueArticles($stats, $meta);
        } catch (Throwable $exception) {
            $stats['bootstrap_failed']++;
            $this->contentProjectQueue->health()->rememberBootstrapFailure($exception->getMessage(), null);
            RuntimeLogger::warning('publishing.connection_bootstrap_failed', [
                'runtime' => 'console',
                'phase' => 'canonical_shared',
                'error' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            throw $exception;
        }

        return $stats;
    }

    /**
     * @param  array{processed: int, published: int, failed: int, skipped: int, bootstrap_failed: int, connections_attempted?: int, connections_skipped?: int}  $stats
     */
    private function runForConnection(SeoDatabaseConnection $connection, array &$stats): void
    {
        $expectedId = (int) $connection->getKey();
        $expectedHash = (string) $connection->hash_id;

        $meta = $this->databaseConnection->bootstrapAndVerifyFromConnection($connection, forceReconnect: true);
        $meta['runtime'] = 'console';
        $meta['resolver'] = 'SeoDatabaseConnectionService';

        $resolvedId = (int) ($meta['connection_id'] ?? 0) ?: null;
        $resolvedHash = (string) ($meta['hash_id'] ?? '');

        RuntimeLogger::info('publishing.connection_ready', [
            'expected_connection_id' => $expectedId,
            'expected_hash_id' => $expectedHash,
            'resolved_connection_id' => $resolvedId,
            'resolved_hash_id' => $resolvedHash !== '' ? $resolvedHash : $expectedHash,
            'connection_id' => $resolvedId ?? $expectedId,
            'hash_id' => $resolvedHash !== '' ? $resolvedHash : $expectedHash,
            'connection_name' => $meta['connection_name'] ?? null,
            'database' => $meta['database'] ?? null,
            'runtime_database' => $meta['runtime_database'] ?? null,
            'type' => $meta['type'] ?? null,
            'resolver' => $meta['resolver'],
            'runtime' => 'console',
            'result' => 'processed',
        ]);

        if ($resolvedId !== null && $expectedId > 0 && $resolvedId !== $expectedId) {
            throw new \RuntimeException(sprintf(
                'Publishing connection mismatch: expected id=%d hash=%s, resolved id=%d hash=%s',
                $expectedId,
                $expectedHash,
                $resolvedId,
                $resolvedHash,
            ));
        }

        $projectStats = $this->contentProjectQueue->dispatchDue($meta);
        $stats['processed'] += $projectStats['processed'];
        $stats['published'] += (int) ($projectStats['published_confirmed'] ?? $projectStats['published'] ?? 0);
        $stats['published_confirmed'] = ($stats['published_confirmed'] ?? 0) + (int) ($projectStats['published_confirmed'] ?? $projectStats['published'] ?? 0);
        $stats['claimed'] = ($stats['claimed'] ?? 0) + (int) ($projectStats['claimed'] ?? $projectStats['claimed_count'] ?? 0);
        $stats['dispatched'] = ($stats['dispatched'] ?? 0) + (int) ($projectStats['dispatched'] ?? $projectStats['dispatched_count'] ?? 0);
        $stats['publisher_started'] = ($stats['publisher_started'] ?? 0) + (int) ($projectStats['publisher_started'] ?? $projectStats['publisher_started_count'] ?? 0);
        $stats['retry_scheduled'] = ($stats['retry_scheduled'] ?? 0) + (int) ($projectStats['retry_scheduled'] ?? $projectStats['retry_wait_count'] ?? 0);
        $stats['failed'] += $projectStats['failed'];
        $stats['skipped'] += $projectStats['skipped'] ?? 0;

        $this->dispatchDueArticles($stats, $meta);

        $this->databaseConnection->forgetBootstrappedHash((string) $connection->hash_id);
        DB::purge($this->databaseConnection->connectionName());
        SeoConnectionContext::reset();
    }

    /**
     * @param  array{processed: int, published: int, failed: int, skipped: int, bootstrap_failed?: int}  $stats
     * @param  array<string, mixed>  $connectionMeta
     */
    private function dispatchDueArticles(array &$stats, array $connectionMeta = []): void
    {
        $this->dueArticles()->each(function (SeoArticle $article) use (&$stats, $connectionMeta): void {
            $stats['processed']++;

            try {
                $this->emitter->emit(BusinessEventName::ArticlePublishRequested, $article, [
                    'article_id' => (int) $article->id,
                    'site_id' => (int) ($article->site_id ?? 0) ?: null,
                    'wp_post_id' => (int) ($article->wordpressLink?->wp_post_id ?? 0) ?: null,
                    'status' => 'publish_requested',
                    'source' => 'scheduled_article_publish_runner',
                    'connection_id' => $connectionMeta['connection_id'] ?? null,
                    'hash_id' => $connectionMeta['hash_id'] ?? null,
                ]);
                $stats['published']++;
            } catch (Throwable $e) {
                $stats['failed']++;
                RuntimeLogger::warning('Scheduled article publish event emit failed.', [
                    'article_id' => $article->id,
                    'connection_id' => $connectionMeta['connection_id'] ?? null,
                    'hash_id' => $connectionMeta['hash_id'] ?? null,
                    'message' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * @return Collection<int, SeoArticle>
     */
    private function dueArticles(): Collection
    {
        return SeoArticle::query()
            ->where('status', 'scheduled')
            ->dueScheduledPublish(now())
            ->hasWpPostId()
            ->whereDoesntHave('projectTasks', static function ($query): void {
                $query->whereNull('archived_at')
                    ->whereHas('project', static function ($projectQuery): void {
                        $projectQuery->whereNull('archived_at');
                    });
            })
            ->orderBy('id')
            ->limit(50)
            ->get();
    }
}
