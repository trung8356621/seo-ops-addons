<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ensure project archive SoT rows exist for articles flagged content_archived_*.
 * Does not drop articles.content_archived_* (compat projection).
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('articles') || ! $schema->hasTable('seo_content_archive_items')) {
            return;
        }

        if (! $schema->hasColumn('articles', 'content_archived_at')) {
            return;
        }

        $rows = DB::connection($this->connection)
            ->table('articles')
            ->whereNotNull('content_archived_at')
            ->whereNotIn('id', function ($q): void {
                $q->select('article_id')->from('seo_content_archive_items');
            })
            ->get(['id', 'site_id', 'content_archived_at', 'content_archived_by']);

        foreach ($rows as $row) {
            DB::connection($this->connection)->table('seo_content_archive_items')->insert([
                'site_id' => (int) $row->site_id,
                'article_id' => (int) $row->id,
                'archived_by' => $row->content_archived_by,
                'archived_at' => $row->content_archived_at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Non-destructive: do not delete archive items that may have gained project links.
    }
};
