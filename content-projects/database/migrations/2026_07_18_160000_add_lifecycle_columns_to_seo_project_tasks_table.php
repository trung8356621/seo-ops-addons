<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
            if (! $schema->hasColumn('seo_project_tasks', 'source_key')) {
                $table->char('source_key', 64)->nullable()->after('source_content');
            }

            if (! $schema->hasColumn('seo_project_tasks', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('archived_from_project_id');
            }

            if (! $schema->hasColumn('seo_project_tasks', 'status_before_archive')) {
                $table->string('status_before_archive', 50)->nullable()->after('archived_at');
            }

            if (! $schema->hasColumn('seo_project_tasks', 'deleted_at')) {
                $table->timestamp('deleted_at')->nullable()->after('updated_at');
            }
        });

        if (! $this->hasIndex('seo_project_tasks', 'seo_project_tasks_project_id_source_key_index')) {
            $schema->table('seo_project_tasks', function (Blueprint $table): void {
                $table->index(['project_id', 'source_key'], 'seo_project_tasks_project_id_source_key_index');
            });
        }

        if (! $this->hasIndex('seo_project_tasks', 'seo_project_tasks_project_id_archived_at_index')) {
            $schema->table('seo_project_tasks', function (Blueprint $table): void {
                $table->index(['project_id', 'archived_at'], 'seo_project_tasks_project_id_archived_at_index');
            });
        }

        if (! $this->hasIndex('seo_project_tasks', 'seo_project_tasks_status_index')) {
            $schema->table('seo_project_tasks', function (Blueprint $table): void {
                $table->index('status', 'seo_project_tasks_status_index');
            });
        }

        if (! $this->hasIndex('seo_project_tasks', 'seo_project_tasks_deleted_at_index')) {
            $schema->table('seo_project_tasks', function (Blueprint $table): void {
                $table->index('deleted_at', 'seo_project_tasks_deleted_at_index');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seo_project_tasks')) {
            return;
        }

        if ($this->hasIndex('seo_project_tasks', 'seo_project_tasks_project_id_source_key_index')) {
            $schema->table('seo_project_tasks', function (Blueprint $table): void {
                $table->dropIndex('seo_project_tasks_project_id_source_key_index');
            });
        }

        if ($this->hasIndex('seo_project_tasks', 'seo_project_tasks_project_id_archived_at_index')) {
            $schema->table('seo_project_tasks', function (Blueprint $table): void {
                $table->dropIndex('seo_project_tasks_project_id_archived_at_index');
            });
        }

        if ($this->hasIndex('seo_project_tasks', 'seo_project_tasks_status_index')) {
            $schema->table('seo_project_tasks', function (Blueprint $table): void {
                $table->dropIndex('seo_project_tasks_status_index');
            });
        }

        if ($this->hasIndex('seo_project_tasks', 'seo_project_tasks_deleted_at_index')) {
            $schema->table('seo_project_tasks', function (Blueprint $table): void {
                $table->dropIndex('seo_project_tasks_deleted_at_index');
            });
        }

        $schema->table('seo_project_tasks', function (Blueprint $table) use ($schema): void {
            $columns = [];
            foreach (['source_key', 'archived_at', 'status_before_archive', 'deleted_at'] as $column) {
                if ($schema->hasColumn('seo_project_tasks', $column)) {
                    $columns[] = $column;
                }
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
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
