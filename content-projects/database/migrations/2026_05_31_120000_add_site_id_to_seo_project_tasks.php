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
        Schema::connection($this->connection)->table('seo_project_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('site_id')
                ->nullable()
                ->after('project_id')
                ->index()
                ->comment('Site / tên miền đích (bảng sites, DB chính)');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('seo_project_tasks', function (Blueprint $table) {
            $table->dropColumn('site_id');
        });
    }
};
