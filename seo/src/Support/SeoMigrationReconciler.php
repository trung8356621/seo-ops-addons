<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Schema;

/**
 * Ghi nhận migration CREATE đã áp dụng khi bảng đã tồn tại (DB thủ công / import schema).
 */
final class SeoMigrationReconciler
{
    /**
     * @param  array<string, string>  $files  migration name => absolute path
     */
    public function reconcileExistingCreateTables(Migrator $migrator, string $connectionName, array $files): int
    {
        $repository = $migrator->getRepository();

        if (! $repository->repositoryExists()) {
            $repository->createRepository();
        }

        $pending = array_values(array_diff(array_keys($files), $repository->getRan()));
        if ($pending === []) {
            return 0;
        }

        $reconciled = 0;
        $batch = $repository->getNextBatchNumber();

        foreach ($pending as $migration) {
            if (! $this->isCreateTableMigration($migration)) {
                continue;
            }

            $path = $files[$migration] ?? null;
            if ($path === null || ! is_readable($path)) {
                continue;
            }

            $tables = $this->extractCreateTableNames((string) file_get_contents($path));
            if ($tables === [] || ! $this->allTablesExist($connectionName, $tables)) {
                continue;
            }

            $repository->log($migration, $batch);
            $reconciled++;
        }

        return $reconciled;
    }

    private function isCreateTableMigration(string $migration): bool
    {
        return (bool) preg_match('/_create_.+_tables?$/', $migration);
    }

    /**
     * @param  list<string>  $tables
     */
    private function allTablesExist(string $connectionName, array $tables): bool
    {
        foreach ($tables as $table) {
            if (! Schema::connection($connectionName)->hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function extractCreateTableNames(string $contents): array
    {
        if (! preg_match_all('/->create\s*\(\s*[\'"]([^\'"]+)[\'"]/', $contents, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }
}
