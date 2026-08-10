<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class QueueInspectWordpressCommand extends Command
{
    protected $signature = 'queue:inspect-wordpress {--limit=100 : Max jobs to scan}';

    protected $description = 'Inspect queued/failed jobs for WordPress-related payloads (database queue).';

    public function handle(): int
    {
        if (config('queue.default') !== 'database') {
            $this->warn('queue.default='.(string) config('queue.default').' — only database payload scan supported.');
        }

        $limit = max(1, (int) $this->option('limit'));
        $this->scanTable('jobs', $limit);
        $this->scanTable('failed_jobs', $limit, payloadColumn: 'payload');

        return self::SUCCESS;
    }

    private function scanTable(string $table, int $limit, string $payloadColumn = 'payload'): void
    {
        $this->info("=== {$table} ===");
        try {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                $this->line('(table missing)');

                return;
            }

            $rows = DB::table($table)->orderByDesc('id')->limit($limit)->get();
            $hits = 0;
            foreach ($rows as $row) {
                $payload = (string) ($row->{$payloadColumn} ?? '');
                if (! str_contains($payload, 'WordPress')
                    && ! str_contains($payload, 'SyncArticleToWordPress')
                    && ! str_contains($payload, 'ExecuteAutomation')
                ) {
                    continue;
                }
                $hits++;
                $class = 'unknown';
                if (preg_match('/"displayName":"([^"]+)"/', $payload, $m) === 1) {
                    $class = $m[1];
                }
                $articleId = null;
                if (preg_match('/articleId";i:(\d+)/', $payload, $m) === 1
                    || preg_match('/"articleId":(\d+)/', $payload, $m) === 1
                ) {
                    $articleId = (int) $m[1];
                }
                $this->line(sprintf(
                    'id=%s queue=%s class=%s article_id=%s created=%s',
                    (string) ($row->id ?? ''),
                    (string) ($row->queue ?? ''),
                    $class,
                    $articleId !== null ? (string) $articleId : '-',
                    (string) ($row->created_at ?? $row->failed_at ?? '-'),
                ));
            }
            if ($hits === 0) {
                $this->line('(no WordPress-related rows in scan window)');
            }
        } catch (\Throwable $e) {
            $this->warn($table.' scan failed: '.$e->getMessage());
        }
    }
}
