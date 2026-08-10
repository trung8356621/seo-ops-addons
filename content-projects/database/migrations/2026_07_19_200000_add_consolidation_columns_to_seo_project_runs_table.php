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

        if (! $schema->hasTable('seo_project_runs')) {
            return;
        }

        if (! $schema->hasColumn('seo_project_runs', 'consolidated_into_run_id')) {
            $schema->table('seo_project_runs', function (Blueprint $table): void {
                $table->unsignedBigInteger('consolidated_into_run_id')->nullable()->after('failed')->index();
                $table->timestamp('consolidated_at')->nullable()->after('consolidated_into_run_id');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seo_project_runs')) {
            return;
        }

        if ($schema->hasColumn('seo_project_runs', 'consolidated_into_run_id')) {
            $schema->table('seo_project_runs', function (Blueprint $table): void {
                $table->dropColumn(['consolidated_into_run_id', 'consolidated_at']);
            });
        }
    }
};
