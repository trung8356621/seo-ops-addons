<?php

declare(strict_types=1);

/**
 * Hot-path composite index for V3 bulk preload by (site_id, wp_post_id).
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
        if (! $schema->hasTable('wordpress_article_links')) {
            return;
        }

        $schema->table('wordpress_article_links', function (Blueprint $table) use ($schema): void {
            if (! $this->hasIndex('wordpress_article_links', 'wal_site_wp_post_idx')) {
                $table->index(['site_id', 'wp_post_id'], 'wal_site_wp_post_idx');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('wordpress_article_links')) {
            return;
        }

        $schema->table('wordpress_article_links', function (Blueprint $table): void {
            $table->dropIndex('wal_site_wp_post_idx');
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $db = \Illuminate\Support\Facades\DB::connection($this->connection);
        $rows = $db->select('SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?', [$indexName]);

        return $rows !== [];
    }
};
