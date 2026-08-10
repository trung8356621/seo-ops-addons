<?php

declare(strict_types=1);

/**
 * Cột `type` cho bảng nội dung SEO.
 * Tên bảng vật lý: `articles` (connection omi_seo_ai). Phần mềm gọi tập này là seo_articles.
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if ($schema->hasColumn('articles', 'type')) {
            return;
        }

        $schema->table('articles', function (Blueprint $table) {
            $table->string('type', 50)
                ->nullable()
                ->after('status')
                ->comment('Phân loại nội dung: article (bài viết), category (danh mục), product (sản phẩm), product_category (danh mục SP)');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasColumn('articles', 'type')) {
            return;
        }

        $schema->table('articles', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
