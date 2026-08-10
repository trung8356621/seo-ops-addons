<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Omnichannel\Addons\ContentProjects\Enums\SeoProjectTaskStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use App\Addons\SeoContentAi\SeoContentAiServiceProvider;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectTaskStatusNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch D — report / optional backfill of free-form seo_project_tasks.status values.
 */
final class ReportSeoProjectTaskStatusCommand extends Command
{
    protected $signature = 'seo:content-project:report-task-status
        {--apply : Write normalized status for mappable legacy values}
        {--chunk=500 : Chunk size}';

    protected $description = 'Report (and optionally normalize) legacy seo_project_tasks.status values.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $chunk = max(50, (int) $this->option('chunk'));
        $connection = SeoContentAiServiceProvider::DB_CONNECTION;

        if (! Schema::connection($connection)->hasTable('seo_project_tasks')) {
            $this->warn('seo_project_tasks missing.');

            return self::SUCCESS;
        }

        $stats = [
            'scanned' => 0,
            'canonical' => 0,
            'mapped' => 0,
            'unknown' => 0,
            'updated' => 0,
        ];
        /** @var array<string, int> $unknownSamples */
        $unknownSamples = [];

        SeoProjectTask::query()
            ->orderBy('id')
            ->chunkById($chunk, function ($tasks) use ($apply, $connection, &$stats, &$unknownSamples): void {
                foreach ($tasks as $task) {
                    if (! $task instanceof SeoProjectTask) {
                        continue;
                    }
                    $stats['scanned']++;
                    $raw = (string) ($task->status ?? '');
                    $normalized = ContentProjectTaskStatusNormalizer::tryNormalize($raw);
                    if (! $normalized instanceof SeoProjectTaskStatus) {
                        $stats['unknown']++;
                        $unknownSamples[$raw] = ($unknownSamples[$raw] ?? 0) + 1;

                        continue;
                    }

                    if ($normalized->value === $raw) {
                        $stats['canonical']++;

                        continue;
                    }

                    $stats['mapped']++;
                    if (! $apply) {
                        continue;
                    }

                    DB::connection($connection)->table('seo_project_tasks')
                        ->where('id', (int) $task->id)
                        ->update(['status' => $normalized->value]);
                    $stats['updated']++;
                }
            });

        $this->info($apply ? 'Applied task status normalization' : 'Dry-run task status report');
        $this->table(array_keys($stats), [array_values($stats)]);
        if ($unknownSamples !== []) {
            $this->warn('Unknown status samples (top):');
            arsort($unknownSamples);
            foreach (array_slice($unknownSamples, 0, 20, true) as $value => $count) {
                $this->line(sprintf('  [%s] x%d', $value === '' ? '(empty)' : $value, $count));
            }
        }

        return self::SUCCESS;
    }
}
