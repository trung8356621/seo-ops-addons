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
        Schema::connection($this->connection)->table('seo_project_tasks', function (Blueprint $table): void {
            $table->string('post_type', 50)
                ->nullable()
                ->after('type')
                ->comment('Loại bài (viết mới): article, product, category, product_category');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('seo_project_tasks', function (Blueprint $table): void {
            $table->dropColumn('post_type');
        });
    }
};
