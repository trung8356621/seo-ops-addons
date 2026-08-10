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
        Schema::connection($this->connection)->table('seo_projects', function (Blueprint $table): void {
            if (! Schema::connection($this->connection)->hasColumn('seo_projects', 'site_id')) {
                $table->unsignedBigInteger('site_id')
                    ->nullable()
                    ->after('user_id')
                    ->index()
                    ->comment('Tên miền / site (bảng sites, DB chính)');
            }
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('seo_projects', function (Blueprint $table): void {
            if (Schema::connection($this->connection)->hasColumn('seo_projects', 'site_id')) {
                $table->dropColumn('site_id');
            }
        });
    }
};
