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
            if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'loai_san_pham')) {
                $table->string('loai_san_pham', 500)
                    ->nullable()
                    ->after('post_type')
                    ->comment('Loại sản phẩm thủ công cho prompt ảnh sản phẩm');
            }
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('seo_project_tasks', function (Blueprint $table): void {
            if (Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'loai_san_pham')) {
                $table->dropColumn('loai_san_pham');
            }
        });
    }
};
