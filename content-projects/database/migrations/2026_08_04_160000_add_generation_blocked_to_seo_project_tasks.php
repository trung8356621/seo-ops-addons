<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator opt-out from automatic generation / retry selection.
 * Reversible; does not archive or delete article content.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_project_tasks')) {
            return;
        }

        Schema::connection($this->connection)->table('seo_project_tasks', function (Blueprint $table): void {
            if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'generation_blocked_at')) {
                $table->timestamp('generation_blocked_at')
                    ->nullable()
                    ->after('publishing_queued_by')
                    ->index()
                    ->comment('Operator skip: exclude from Generate/Retry selection');
            }
            if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'generation_blocked_by')) {
                $table->unsignedInteger('generation_blocked_by')
                    ->nullable()
                    ->after('generation_blocked_at');
            }
            if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'generation_block_reason')) {
                $table->string('generation_block_reason', 255)
                    ->nullable()
                    ->after('generation_blocked_by');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_project_tasks')) {
            return;
        }

        Schema::connection($this->connection)->table('seo_project_tasks', function (Blueprint $table): void {
            foreach (['generation_block_reason', 'generation_blocked_by', 'generation_blocked_at'] as $column) {
                if (Schema::connection($this->connection)->hasColumn('seo_project_tasks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
