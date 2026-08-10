<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services;

use Omnichannel\Addons\SearchFoundation\Jobs\ImportSeoDatabaseJob;
use Omnichannel\Addons\SearchFoundation\Support\SeoSqlStreamParser;
use App\Models\SeoDatabaseConnection;
use App\Models\Site;
use App\Models\TaskJob;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PDO;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

final class SeoDatabaseBackupService
{
    public function __construct(
        private readonly SeoDatabaseConnectionService $connectionService,
        private readonly SeoSqlStreamParser $sqlParser,
    ) {}

    public function exportConnection(SeoDatabaseConnection $connection): string
    {
        $this->connectionService->bootstrapFromConnection($connection);

        $connectionName = $this->connectionService->connectionName();
        $databaseName = (string) DB::connection($connectionName)->getDatabaseName();
        $safeDb = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $databaseName) ?: 'seo_db';
        $filename = sprintf('seo-backup-%s-%s.sql.gz', $safeDb, now()->format('Ymd-His'));
        $absolutePath = $this->temporaryPath($filename);

        $gzip = gzopen($absolutePath, 'wb9');
        if ($gzip === false) {
            throw new RuntimeException('Không tạo được file backup tạm.');
        }

        try {
            $this->writeGzipLine($gzip, '-- SEO Content AI Database Backup');
            $this->writeGzipLine($gzip, '-- Connection: '.addslashes((string) $connection->name));
            $this->writeGzipLine($gzip, '-- Database: '.$databaseName);
            $this->writeGzipLine($gzip, '-- Generated: '.now()->toIso8601String());
            $this->writeGzipLine($gzip, '');
            $this->writeGzipLine($gzip, 'SET NAMES utf8mb4;');
            $this->writeGzipLine($gzip, 'SET FOREIGN_KEY_CHECKS=0;');
            $this->writeGzipLine($gzip, 'SET SQL_MODE=\'NO_AUTO_VALUE_ON_ZERO\';');
            $this->writeGzipLine($gzip, '');

            foreach ($this->listTables($connectionName) as $table) {
                $this->exportTableStructure($gzip, $connectionName, $table);
                $this->exportTableData($gzip, $connectionName, $table);
            }

            $this->writeGzipLine($gzip, '');
            $this->writeGzipLine($gzip, 'SET FOREIGN_KEY_CHECKS=1;');
        } finally {
            gzclose($gzip);
        }

        return $absolutePath;
    }

    public function downloadResponse(SeoDatabaseConnection $connection): BinaryFileResponse
    {
        $path = $this->exportConnection($connection);
        $filename = basename($path);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/gzip',
        ])->deleteFileAfterSend(true);
    }

    /**
     * @param  (Closure(int): void)|null  $onProgress
     * @return array{statements: int, queued: bool, task_job_id: int|null}
     */
    public function importConnection(
        SeoDatabaseConnection $connection,
        string $filePath,
        ?Closure $onProgress = null,
        bool $forceQueue = false,
    ): array {
        $this->assertSafeImportFile($filePath);

        $fileSize = filesize($filePath);
        if ($fileSize === false) {
            throw new RuntimeException('Không đọc được kích thước file import.');
        }

        $threshold = (int) config('seo-content-ai.db_import_queue_threshold', 5 * 1024 * 1024);
        $shouldQueue = $forceQueue || ($fileSize >= $threshold && config('queue.default') !== 'sync');

        if ($shouldQueue) {
            $taskJobId = $this->createImportTaskJob($connection);

            ImportSeoDatabaseJob::dispatch(
                (int) $connection->getKey(),
                $filePath,
                $taskJobId,
            );

            return [
                'statements' => 0,
                'queued' => true,
                'task_job_id' => $taskJobId,
            ];
        }

        try {
            $result = $this->runImport($connection, $filePath, $onProgress);
        } finally {
            $this->deleteFileIfExists($filePath);
        }

        return [
            'statements' => $result['statements'],
            'queued' => false,
            'task_job_id' => null,
        ];
    }

    /**
     * @param  (Closure(int): void)|null  $onProgress
     * @return array{statements: int}
     */
    public function runImport(SeoDatabaseConnection $connection, string $filePath, ?Closure $onProgress = null): array
    {
        $this->assertSafeImportFile($filePath);
        $this->connectionService->bootstrapFromConnection($connection);

        $connectionName = $this->connectionService->connectionName();
        $handle = $this->openReadableStream($filePath);
        $totalBytes = filesize($filePath) ?: null;

        try {
            $db = DB::connection($connectionName);
            $db->unprepared('SET FOREIGN_KEY_CHECKS=0;');

            $stats = $this->sqlParser->executeStream(
                $handle,
                function (string $statement) use ($db): void {
                    $db->unprepared($statement);
                },
                is_int($totalBytes) ? $totalBytes : null,
                $onProgress,
            );

            $db->unprepared('SET FOREIGN_KEY_CHECKS=1;');

            return ['statements' => $stats['statements']];
        } catch (Throwable $exception) {
            try {
                DB::connection($connectionName)->unprepared('SET FOREIGN_KEY_CHECKS=1;');
            } catch (Throwable) {
                // ignore secondary failure
            }

            throw $exception;
        } finally {
            $this->closeStream($handle);
        }
    }

    public function resolveStoredImportPath(string $storagePath): string
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($storagePath)) {
            throw new RuntimeException('File import không tồn tại trên storage.');
        }

        return $disk->path($storagePath);
    }

    public function assertSafeImportFile(string $filePath): void
    {
        if (! is_file($filePath)) {
            throw new RuntimeException('File import không hợp lệ.');
        }

        $basename = strtolower(basename($filePath));
        $allowed = str_ends_with($basename, '.sql')
            || str_ends_with($basename, '.sql.gz')
            || str_ends_with($basename, '.gz');

        if (! $allowed) {
            throw new RuntimeException('Chỉ chấp nhận file .sql hoặc .sql.gz.');
        }

        $handle = $this->openReadableStream($filePath);
        try {
            $sample = (string) fread($handle, 1024);
        } finally {
            $this->closeStream($handle);
        }

        if (stripos($sample, '<?php') !== false || stripos($sample, '<?=') !== false) {
            throw new RuntimeException('File upload không hợp lệ (phát hiện mã PHP).');
        }
    }

    private function createImportTaskJob(SeoDatabaseConnection $connection): int
    {
        $siteId = $this->resolveTaskJobSiteId($connection);

        /** @var TaskJob $task */
        $task = TaskJob::query()->create([
            'site_id' => $siteId,
            'task_type' => 'seo_db_import',
            'status' => 'pending',
            'progress_percent' => 0,
        ]);

        return (int) $task->getKey();
    }

    private function resolveTaskJobSiteId(SeoDatabaseConnection $connection): int
    {
        $userIds = $connection->users()->pluck('users.id');

        if ($userIds->isNotEmpty()) {
            $siteId = Site::query()
                ->whereIn('user_id', $userIds)
                ->orderBy('id')
                ->value('id');

            if ($siteId !== null) {
                return (int) $siteId;
            }
        }

        $fallbackSiteId = Site::query()->orderBy('id')->value('id');
        if ($fallbackSiteId === null) {
            throw new RuntimeException('Không tìm thấy site để theo dõi tiến trình import.');
        }

        return (int) $fallbackSiteId;
    }

    /**
     * @return list<string>
     */
    private function listTables(string $connectionName): array
    {
        $databaseName = (string) DB::connection($connectionName)->getDatabaseName();
        $key = 'Tables_in_'.$databaseName;

        return collect(DB::connection($connectionName)->select('SHOW TABLES'))
            ->map(fn (object $row): string => (string) ($row->{$key} ?? ''))
            ->filter(fn (string $table): bool => $table !== '')
            ->values()
            ->all();
    }

    /**
     * @param  resource  $gzip
     */
    private function exportTableStructure($gzip, string $connectionName, string $table): void
    {
        $quotedTable = $this->quoteIdentifier($table);
        $rows = DB::connection($connectionName)->select('SHOW CREATE TABLE '.$quotedTable);
        $row = (array) ($rows[0] ?? []);
        $createSql = (string) ($row['Create Table'] ?? $row['Create View'] ?? '');

        if ($createSql === '') {
            return;
        }

        $this->writeGzipLine($gzip, '');
        $this->writeGzipLine($gzip, '-- Structure for table '.$table);
        $this->writeGzipLine($gzip, 'DROP TABLE IF EXISTS '.$quotedTable.';');
        $this->writeGzipLine($gzip, $createSql.';');
    }

    /**
     * @param  resource  $gzip
     */
    private function exportTableData($gzip, string $connectionName, string $table): void
    {
        $chunkSize = max(100, (int) config('seo-content-ai.db_export_chunk_size', 750));
        $insertBatchSize = max(1, (int) config('seo-content-ai.db_export_insert_batch_size', 100));
        $quotedTable = $this->quoteIdentifier($table);
        $pdo = DB::connection($connectionName)->getPdo();
        $offset = 0;
        $hasRows = false;

        while (true) {
            $rows = DB::connection($connectionName)
                ->table($table)
                ->offset($offset)
                ->limit($chunkSize)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            if (! $hasRows) {
                $this->writeGzipLine($gzip, '');
                $this->writeGzipLine($gzip, '-- Data for table '.$table);
                $hasRows = true;
            }

            $columns = array_keys((array) $rows->first());
            $columnList = implode(', ', array_map(fn (string $column): string => $this->quoteIdentifier($column), $columns));

            foreach ($rows->chunk($insertBatchSize) as $batch) {
                $valuesSql = $batch
                    ->map(function (object $row) use ($columns, $pdo): string {
                        $values = [];
                        $arrayRow = (array) $row;

                        foreach ($columns as $column) {
                            $values[] = $this->quoteValue($arrayRow[$column] ?? null, $pdo);
                        }

                        return '('.implode(', ', $values).')';
                    })
                    ->implode(",\n");

                $this->writeGzipLine(
                    $gzip,
                    'INSERT INTO '.$quotedTable.' ('.$columnList.") VALUES\n".$valuesSql.';',
                );
            }

            $offset += $chunkSize;
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function quoteValue(mixed $value, PDO $pdo): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $pdo->quote((string) $value);
    }

    /**
     * @param  resource  $gzip
     */
    private function writeGzipLine($gzip, string $line): void
    {
        gzwrite($gzip, $line."\n");
    }

    private function temporaryPath(string $filename): string
    {
        $directory = storage_path('app/'.trim((string) config('seo-content-ai.db_backup_storage_dir', 'seo-db-backups'), '/'));
        File::ensureDirectoryExists($directory);

        return $directory.DIRECTORY_SEPARATOR.$filename;
    }

    /**
     * @return resource
     */
    private function openReadableStream(string $filePath)
    {
        if (str_ends_with(strtolower($filePath), '.gz')) {
            $handle = gzopen($filePath, 'rb');
            if ($handle === false) {
                throw new RuntimeException('Không mở được file nén .gz.');
            }

            return $handle;
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Không mở được file SQL.');
        }

        return $handle;
    }

    /**
     * @param  resource  $handle
     */
    private function closeStream($handle): void
    {
        if (! is_resource($handle)) {
            return;
        }

        if (get_resource_type($handle) === 'gzstream') {
            gzclose($handle);

            return;
        }

        fclose($handle);
    }

    private function deleteFileIfExists(string $filePath): void
    {
        if (is_file($filePath)) {
            @unlink($filePath);
        }
    }
}
