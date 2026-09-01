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
        $schema = Schema::connection($this->connection);
        $tableName = 'seo_project_archive_item_social_links';

        if (! $schema->hasTable($tableName)) {
            return;
        }

        if (! $schema->hasColumn($tableName, 'url_hash')) {
            $schema->table($tableName, function (Blueprint $table): void {
                $table->char('url_hash', 64)->nullable()->after('url');
            });

            DB::connection($this->connection)->table($tableName)->orderBy('id')->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $urlKey = rtrim(strtolower(trim((string) ($row->url ?? ''))), '/');
                    DB::connection($this->connection)->table('seo_project_archive_item_social_links')
                        ->where('id', $row->id)
                        ->update(['url_hash' => hash('sha256', $urlKey)]);
                }
            });

            $schema->table($tableName, function (Blueprint $table): void {
                $table->char('url_hash', 64)->nullable(false)->change();
            });
        }

        $indexes = collect($schema->getIndexes($tableName))->pluck('name')->all();
        if (! in_array('seo_project_archive_item_social_links_unique', $indexes, true)) {
            $schema->table($tableName, function (Blueprint $table): void {
                $table->unique(['archive_item_id', 'url_hash'], 'seo_project_archive_item_social_links_unique');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $tableName = 'seo_project_archive_item_social_links';

        if (! $schema->hasTable($tableName)) {
            return;
        }

        $indexes = collect($schema->getIndexes($tableName))->pluck('name')->all();
        if (in_array('seo_project_archive_item_social_links_unique', $indexes, true)) {
            $schema->table($tableName, function (Blueprint $table): void {
                $table->dropUnique('seo_project_archive_item_social_links_unique');
            });
        }

        if ($schema->hasColumn($tableName, 'url_hash')) {
            $schema->table($tableName, function (Blueprint $table): void {
                $table->dropColumn('url_hash');
            });
        }
    }
};
