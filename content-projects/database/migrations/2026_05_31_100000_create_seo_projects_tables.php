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
        Schema::connection($this->connection)->create('seo_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Tên dự án content');
            $table->unsignedBigInteger('user_id')->index()->comment('Người viết bài được chỉ định');
            $table->date('month')->comment('Tháng thực hiện (ngày đầu tiên của tháng)');
            $table->string('status', 50)->default('running')->comment('pending, running, completed, paused');
            $table->unsignedInteger('total_tasks')->default(0)->comment('Tổng số bài/từ khóa đăng ký');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['month', 'status']);
        });

        Schema::connection($this->connection)->create('seo_project_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')
                ->constrained('seo_projects')
                ->cascadeOnDelete();
            $table->enum('type', ['rewrite', 'new_keyword'])
                ->comment('rewrite: viết lại bài lỗi, new_keyword: từ khóa mới');
            $table->string('source_content')->comment('Từ khóa hoặc tiêu đề bài cần sửa');
            $table->date('target_date')->comment('Ngày KPI (phân bổ tuần tự trong tháng)');
            $table->string('status', 50)->default('pending')
                ->comment('pending, writing, reviewing, completed, failed');
            $table->timestamps();

            $table->index(['project_id', 'target_date']);
            $table->index(['target_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_project_tasks');
        Schema::connection($this->connection)->dropIfExists('seo_projects');
    }
};
