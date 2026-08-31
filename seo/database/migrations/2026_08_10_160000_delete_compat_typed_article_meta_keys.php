<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 7 §N — delete COMPAT typed keys with zero remaining PHP readers.
 *
 * Before delete: backfill typed owners from leftover meta where column empty.
 * Idempotent: re-run deletes 0 rows after first pass.
 *
 * Deleted: seo_scoring_details, seo_title, wp_slug, wp_post_type
 * Note: wp_post_content retained at time of this migration; later deleted separately.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    /**
     * @var list<string>
     */
    private const DEAD_META_KEYS = [
        'seo_scoring_details',
        'seo_title',
        'wp_slug',
        'wp_post_type',
    ];

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('article_meta') || ! $schema->hasTable('articles')) {
            return;
        }

        $db = DB::connection($this->connection);

        // Backfill articles.slug from wp_slug when slug empty.
        if ($schema->hasColumn('articles', 'slug')) {
            $db->affectingStatement(
                "UPDATE articles a
                 INNER JOIN article_meta m
                    ON m.article_id = a.id AND m.meta_key = 'wp_slug'
                 SET a.slug = m.meta_value
                 WHERE (a.slug IS NULL OR a.slug = '')
                   AND m.meta_value IS NOT NULL
                   AND TRIM(m.meta_value) <> ''"
            );
        }

        // Backfill articles.type from wp_post_type when type empty.
        if ($schema->hasColumn('articles', 'type')) {
            $db->affectingStatement(
                "UPDATE articles a
                 INNER JOIN article_meta m
                    ON m.article_id = a.id AND m.meta_key = 'wp_post_type'
                 SET a.type = CASE LOWER(TRIM(m.meta_value))
                    WHEN 'product' THEN 'product'
                    WHEN 'product_cat' THEN 'product_category'
                    WHEN 'product_category' THEN 'product_category'
                    WHEN 'category' THEN 'category'
                    WHEN 'page' THEN 'page'
                    WHEN 'post' THEN 'article'
                    WHEN 'article' THEN 'article'
                    ELSE a.type
                 END
                 WHERE (a.type IS NULL OR a.type = '')
                   AND m.meta_value IS NOT NULL
                   AND TRIM(m.meta_value) <> ''"
            );
        }

        // seo_title mirrored articles.title — no title overwrite (title may already differ).

        $placeholders = implode(', ', array_fill(0, count(self::DEAD_META_KEYS), '?'));

        $before = (int) $db->table('article_meta')
            ->whereIn('meta_key', self::DEAD_META_KEYS)
            ->count();

        $deleted = $db->affectingStatement(
            "DELETE FROM article_meta WHERE meta_key IN ({$placeholders})",
            self::DEAD_META_KEYS,
        );

        if ($before > 0 || $deleted > 0) {
            echo "article_meta COMPAT typed keys: matched={$before}, deleted={$deleted}\n";
        }
    }

    public function down(): void
    {
        // Irreversible data cleanup — no restore of deleted meta rows.
    }
};
