<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reporting-only Content Manager review stamp (In Review presentation).
 * Not a lifecycle / workflow gate.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seo_project_tasks')) {
            return;
        }

        $schema->table('seo_project_tasks', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('seo_project_tasks', 'content_manager_reviewed_at')) {
                $table->timestamp('content_manager_reviewed_at')->nullable()->after('completed_at');
            }
            if (! $schema->hasColumn('seo_project_tasks', 'content_manager_reviewed_by')) {
                $table->unsignedBigInteger('content_manager_reviewed_by')->nullable()->after('content_manager_reviewed_at');
            }
        });

        if (! $this->hasIndex('seo_project_tasks', 'seo_project_tasks_project_cm_reviewed_at_index')) {
            $schema->table('seo_project_tasks', function (Blueprint $table): void {
                $table->index(
                    ['project_id', 'content_manager_reviewed_at'],
                    'seo_project_tasks_project_cm_reviewed_at_index',
                );
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seo_project_tasks')) {
            return;
        }

        if ($this->hasIndex('seo_project_tasks', 'seo_project_tasks_project_cm_reviewed_at_index')) {
            $schema->table('seo_project_tasks', function (Blueprint $table): void {
                $table->dropIndex('seo_project_tasks_project_cm_reviewed_at_index');
            });
        }

        $schema->table('seo_project_tasks', function (Blueprint $table) use ($schema): void {
            if ($schema->hasColumn('seo_project_tasks', 'content_manager_reviewed_by')) {
                $table->dropColumn('content_manager_reviewed_by');
            }
            if ($schema->hasColumn('seo_project_tasks', 'content_manager_reviewed_at')) {
                $table->dropColumn('content_manager_reviewed_at');
            }
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $indexes = Schema::connection($this->connection)->getIndexes($table);

        foreach ($indexes as $index) {
            if (($index['name'] ?? '') === $indexName) {
                return true;
            }
        }

        return false;
    }
};
