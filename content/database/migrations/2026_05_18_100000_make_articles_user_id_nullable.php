<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        Schema::connection($this->connection)->table('articles', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        // Bài đồng bộ từ CMS: chưa có tác giả hệ thống cho đến khi user sửa bài.
        DB::connection($this->connection)
            ->table('articles')
            ->whereNotNull('wp_post_id')
            ->update(['user_id' => null]);
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('articles', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
