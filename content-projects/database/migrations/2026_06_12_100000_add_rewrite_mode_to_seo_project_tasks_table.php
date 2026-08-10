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
            if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'rewrite_mode')) {
                $table->string('rewrite_mode', 32)
                    ->default('keyword')
                    ->after('source_content')
                    ->comment('rewrite: keyword | content');
            }

            if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'rewrite_notes')) {
                $table->text('rewrite_notes')
                    ->nullable()
                    ->after('rewrite_mode')
                    ->comment('Ghi chú khi viết lại theo nội dung');
            }
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('seo_project_tasks', function (Blueprint $table): void {
            if (Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'rewrite_notes')) {
                $table->dropColumn('rewrite_notes');
            }

            if (Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'rewrite_mode')) {
                $table->dropColumn('rewrite_mode');
            }
        });
    }
};
