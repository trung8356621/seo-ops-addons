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

        if ($schema->hasTable('articles')) {
            $schema->table('articles', function (Blueprint $table) use ($schema): void {
                foreach (['archived_from_project_id', 'archived_by', 'archived_at'] as $column) {
                    if ($schema->hasColumn('articles', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (! $schema->hasTable('seo_project_archives')) {
            $schema->create('seo_project_archives', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('project_id')
                    ->constrained('seo_projects')
                    ->cascadeOnDelete();
                $table->unsignedBigInteger('archived_by')->index();
                $table->string('note', 500)->nullable();
                $table->unsignedInteger('articles_count')->default(0);
                $table->timestamps();

                $table->index(['project_id', 'created_at']);
            });
        }

        if (! $schema->hasTable('seo_project_archive_items')) {
            $schema->create('seo_project_archive_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('seo_project_archive_id')
                    ->constrained('seo_project_archives')
                    ->cascadeOnDelete();
                $table->unsignedBigInteger('article_id')->index();
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['seo_project_archive_id', 'article_id'], 'seo_project_archive_items_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_project_archive_items');
        Schema::connection($this->connection)->dropIfExists('seo_project_archives');
    }
};
