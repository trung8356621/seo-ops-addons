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
        if (! Schema::connection($this->connection)->hasTable('seo_project_tasks')) {
            return;
        }

        Schema::connection($this->connection)->table('seo_project_tasks', function (Blueprint $table): void {
            if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'review_checkpoint_enabled')) {
                $table->boolean('review_checkpoint_enabled')->default(false);
            }

            if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'generation_paused_at')) {
                $table->timestamp('generation_paused_at')->nullable();
            }

            if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'generation_pause_reason')) {
                $table->string('generation_pause_reason', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_project_tasks')) {
            return;
        }

        $columns = [
            'review_checkpoint_enabled',
            'generation_paused_at',
            'generation_pause_reason',
        ];

        Schema::connection($this->connection)->table('seo_project_tasks', function (Blueprint $table) use ($columns): void {
            foreach ($columns as $column) {
                if (Schema::connection($this->connection)->hasColumn('seo_project_tasks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
