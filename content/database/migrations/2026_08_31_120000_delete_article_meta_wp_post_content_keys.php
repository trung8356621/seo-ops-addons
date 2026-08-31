<?php

declare(strict_types=1);

/**
 * Remove legacy body-cache meta keys. Canonical content is articles.body only.
 *
 * Pre-delete audit (omi_seo_ai, 2026-08-31):
 * - body empty + wp_post_content populated = 2714 (all have wordpress_article_links)
 * - body empty + wp_post_content_source populated = 7274
 * - orphans without WP link = 0
 *
 * No body backfill: WP-synced articles intentionally keep body null until
 * Article Editor open fetches WP content JSON and persists articles.body.
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    /**
     * @var list<string>
     */
    private const DEAD_META_KEYS = [
        'wp_post_content',
        'wp_post_content_source',
    ];

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('article_meta')) {
            return;
        }

        $db = DB::connection($this->connection);
        $placeholders = implode(', ', array_fill(0, count(self::DEAD_META_KEYS), '?'));

        $before = (int) $db->table('article_meta')
            ->whereIn('meta_key', self::DEAD_META_KEYS)
            ->count();

        $deleted = $db->affectingStatement(
            "DELETE FROM article_meta WHERE meta_key IN ({$placeholders})",
            self::DEAD_META_KEYS,
        );

        if ($before > 0 || $deleted > 0) {
            echo "article_meta wp_post_content keys: matched={$before}, deleted={$deleted}\n";
        }
    }

    public function down(): void
    {
        // Irreversible data cleanup — no restore of deleted meta rows.
    }
};
