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

        if (! $schema->hasTable('seo_content_archive_items')) {
            return;
        }

        if ($schema->hasColumn('seo_content_archive_items', 'task_id')) {
            return;
        }

        $schema->table('seo_content_archive_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('task_id')->nullable()->after('article_id')->index();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seo_content_archive_items')) {
            return;
        }

        if (! $schema->hasColumn('seo_content_archive_items', 'task_id')) {
            return;
        }

        $schema->table('seo_content_archive_items', function (Blueprint $table): void {
            $table->dropIndex(['task_id']);
            $table->dropColumn('task_id');
        });
    }
};
