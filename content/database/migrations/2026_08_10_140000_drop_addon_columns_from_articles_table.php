<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 5: drop addon-owned columns from articles after extension SoT + routing trait.
 * Idempotent: skips missing columns / missing destination tables.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    /** @var list<string> */
    private array $seoColumns = [
        'seo_score',
        'skip_seo_score',
        'internal_link_count',
        'external_link_count',
        'indexed_at',
        'previous_indexed_at',
    ];

    /** @var list<string> */
    private array $wpColumns = [
        'wp_post_id',
        'wp_sync_status',
        'wp_sync_job_id',
        'last_synced_at',
    ];

    /** @var list<string> */
    private array $mediaColumns = [
        'featured_thumb_url',
        'featured_media_id',
        'featured_image_status',
        'featured_image_source',
    ];

    /** @var list<string> */
    private array $publishingColumns = [
        'published_at',
    ];

    /** @var list<string> */
    private array $archiveColumns = [
        'content_archived_at',
        'content_archived_by',
    ];

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('articles')) {
            return;
        }

        $this->assertDestination('seo_article_profiles', 'articles', 'seo_score');
        $this->assertDestination('wordpress_article_links', 'articles', 'wp_post_id');
        $this->assertDestination('publishing_article_states', 'articles', 'published_at');
        // media/archive may be sparse — table existence only
        if (! $schema->hasTable('article_media_states') || ! $schema->hasTable('seo_content_archive_items')) {
            throw new RuntimeException('Task5 drop aborted: media/archive destination tables missing.');
        }

        $this->dropIndexesIfExist([
            'articles_wp_post_id_index',
            'articles_site_id_seo_score_index',
            'articles_content_archived_at_index',
            'articles_content_archived_by_index',
            'articles_wp_sync_status_index',
            'articles_wp_sync_job_id_index',
            'articles_last_synced_at_index',
            'articles_featured_media_id_index',
            'articles_featured_image_status_index',
        ]);

        $this->dropColumns($this->seoColumns);
        $this->dropColumns($this->wpColumns);
        $this->dropColumns($this->mediaColumns);
        $this->dropColumns($this->publishingColumns);
        $this->dropColumns($this->archiveColumns);

        // Restore site_id index if composite seo_score index removed it.
        if ($schema->hasColumn('articles', 'site_id')) {
            $hasSiteIndex = collect(DB::connection($this->connection)->select('SHOW INDEX FROM articles WHERE Column_name = ?', ['site_id']))
                ->isNotEmpty();
            if (! $hasSiteIndex) {
                $schema->table('articles', function (Blueprint $table): void {
                    $table->index('site_id');
                });
            }
        }
    }

    /**
     * @param  list<string>  $indexNames
     */
    private function dropIndexesIfExist(array $indexNames): void
    {
        $existing = collect(DB::connection($this->connection)->select('SHOW INDEX FROM articles'))
            ->pluck('Key_name')
            ->unique()
            ->all();

        $toDrop = array_values(array_intersect($indexNames, $existing));
        if ($toDrop === []) {
            return;
        }

        Schema::connection($this->connection)->table('articles', function (Blueprint $table) use ($toDrop): void {
            foreach ($toDrop as $name) {
                $table->dropIndex($name);
            }
        });
    }

    public function down(): void
    {
        // Non-reversible without full recreate; leave empty intentionally.
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropColumns(array $columns): void
    {
        $schema = Schema::connection($this->connection);
        $existing = [];
        foreach ($columns as $column) {
            if ($schema->hasColumn('articles', $column)) {
                $existing[] = $column;
            }
        }
        if ($existing === []) {
            return;
        }

        $schema->table('articles', function (Blueprint $table) use ($existing): void {
            $table->dropColumn($existing);
        });
    }

    private function assertDestination(string $destTable, string $srcTable, string $srcColumn): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable($destTable)) {
            throw new RuntimeException("Task5 drop aborted: missing {$destTable}");
        }
        if (! $schema->hasColumn($srcTable, $srcColumn)) {
            return;
        }

        $srcCount = (int) DB::connection($this->connection)->table($srcTable)->whereNotNull($srcColumn)->count();
        $destCount = (int) DB::connection($this->connection)->table($destTable)->count();
        if ($srcCount > 0 && $destCount === 0) {
            throw new RuntimeException("Task5 drop aborted: {$destTable} empty while {$srcTable}.{$srcColumn} has data");
        }
    }
};
