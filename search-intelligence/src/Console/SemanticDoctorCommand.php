<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticHttpException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticAnalyticsClient;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicGroupingProviderMode;

/**
 * Lightweight Laravel probe of seo-ops-semantic health endpoints.
 * Does not duplicate Python doctor internals.
 */
final class SemanticDoctorCommand extends Command
{
    protected $signature = 'semantic:doctor';

    protected $description = 'Check seo-ops-semantic health/ready and report service status';

    public function handle(SemanticAnalyticsClient $client): int
    {
        $base = (string) config('semantic.url', 'http://127.0.0.1:8088');
        $this->line('Semantic URL: '.$base);
        $this->line('TOPIC_GROUPING_PROVIDER: '.TopicGroupingProviderMode::current());
        $this->line('SEMANTIC_ENABLED: '.(config('semantic.enabled') ? 'true' : 'false'));

        $t0 = hrtime(true);
        try {
            $ready = $client->getJson('/health/ready');
        } catch (SemanticHttpException $e) {
            $this->error('Semantic API      FAIL ('.$e->errorCode.')');
            $this->line($e->getMessage());

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Semantic API      FAIL');
            $this->line($e->getMessage());

            return self::FAILURE;
        }
        $latencyMs = (int) round((hrtime(true) - $t0) / 1e6);

        $status = (string) ($ready['status'] ?? '');
        $ok = $status === 'ok' && ($ready['ready'] ?? false) === true;

        $postgres = is_array($ready['postgres'] ?? null) ? $ready['postgres'] : [];
        $pgvector = is_array($ready['pgvector'] ?? null) ? $ready['pgvector'] : [];
        $embedding = is_array($ready['embedding'] ?? null) ? $ready['embedding'] : [];

        $this->line('Semantic API      '.($ok ? 'OK' : 'FAIL'));
        $this->line('PostgreSQL        '.$this->componentStatus($postgres));
        $this->line('pgvector          '.$this->componentStatus($pgvector));
        $this->line('Embedding         '.$this->componentStatus($embedding));
        $this->line('Model             '.(string) ($embedding['model'] ?? 'n/a'));
        $this->line('Dimensions        '.(string) ($embedding['dimensions'] ?? 'n/a'));
        $this->line('Latency           '.$latencyMs.' ms');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $component
     */
    private function componentStatus(array $component): string
    {
        $status = (string) ($component['status'] ?? 'unknown');
        $version = isset($component['version']) ? ' ('.$component['version'].')' : '';

        return strtoupper($status) === 'OK' ? 'OK'.$version : strtoupper($status).$version;
    }
}
