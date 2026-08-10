<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Jobs;

use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseBackupService;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use App\Models\SeoDatabaseConnection;
use App\Models\TaskJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class ImportSeoDatabaseJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public int $connectionId,
        public string $tempFilePath,
        public int $taskJobId,
    ) {}

    public function handle(
        SeoDatabaseBackupService $backupService,
        SeoDatabaseConnectionService $connectionService,
    ): void {
        /** @var TaskJob|null $task */
        $task = TaskJob::query()->find($this->taskJobId);

        /** @var SeoDatabaseConnection|null $connection */
        $connection = SeoDatabaseConnection::query()->find($this->connectionId);

        if ($task === null || $connection === null) {
            $this->cleanupTempFile();

            return;
        }

        $task->update([
            'status' => 'running',
            'started_at' => now(),
            'progress_percent' => 0,
            'error_log' => null,
        ]);

        try {
            $connectionService->bootstrapByConnectionId($this->connectionId);

            $result = $backupService->runImport(
                $connection,
                $this->tempFilePath,
                function (int $percent) use ($task): void {
                    $task->update(['progress_percent' => $percent]);
                },
            );

            $task->update([
                'status' => 'completed',
                'progress_percent' => 100,
                'finished_at' => now(),
                'error_log' => 'Imported '.$result['statements'].' SQL statements.',
            ]);
        } catch (Throwable $exception) {
            $task->update([
                'status' => 'failed',
                'progress_percent' => $task->progress_percent,
                'finished_at' => now(),
                'error_log' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            $this->cleanupTempFile();
        }
    }

    public function failed(?Throwable $exception): void
    {
        TaskJob::query()
            ->whereKey($this->taskJobId)
            ->where('status', '!=', 'completed')
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_log' => $exception?->getMessage() ?? 'Import SEO database failed.',
            ]);

        $this->cleanupTempFile();
    }

    private function cleanupTempFile(): void
    {
        if (is_file($this->tempFilePath)) {
            @unlink($this->tempFilePath);
        }
    }
}
