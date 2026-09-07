<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional per-item override for article generation strategy (sectioned_free test path).
 * Default remains null ⇒ single_pass (unchanged normal/paid pipeline).
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
            if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'generation_strategy_override')) {
                $table->string('generation_strategy_override', 32)->nullable()->after('generation_mode_override');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_project_tasks')) {
            return;
        }

        if (Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'generation_strategy_override')) {
            Schema::connection($this->connection)->table('seo_project_tasks', function (Blueprint $table): void {
                $table->dropColumn('generation_strategy_override');
            });
        }
    }
};
