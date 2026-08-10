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
        if (! Schema::connection($this->connection)->hasTable('seo_project_runs')) {
            return;
        }

        Schema::connection($this->connection)->table('seo_project_runs', function (Blueprint $table): void {
            if (! Schema::connection($this->connection)->hasColumn('seo_project_runs', 'settings')) {
                $table->json('settings')->nullable()->after('items');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_project_runs')) {
            return;
        }

        Schema::connection($this->connection)->table('seo_project_runs', function (Blueprint $table): void {
            if (Schema::connection($this->connection)->hasColumn('seo_project_runs', 'settings')) {
                $table->dropColumn('settings');
            }
        });
    }
};
